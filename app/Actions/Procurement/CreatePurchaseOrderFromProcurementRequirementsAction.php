<?php

namespace App\Actions\Procurement;

use App\Actions\Concerns\MatchesActorTenant;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PurchaseOrderNumberGenerator;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create the real supplier purchase order (BCF) for one or more open procurement
 * requirements of the same supplier and store — an explicit financial commitment,
 * never created automatically.
 *
 * Rules: procurement.manage in the requirements' organisation and store; every
 * requirement SUPPLIER_CONFIRMED, of that active supplier, on a still-existing
 * line of a DRAFT or CONFIRMED Sales Order (never a cancelled one). Ordering
 * normally happens while the order is still a draft: it is the received goods,
 * not the purchase order, that later make the order confirmable. The ordered
 * quantity defaults to what is not yet on an active purchase order and can never
 * exceed it, so a second click / a second order is refused, and a partly
 * ordered requirement only leaves its remainder. A requirement whose whole
 * quantity is then on purchase orders moves to ORDERED through
 * OrderProcurementAction. A client operation id makes a retried submit return
 * the same purchase order.
 */
class CreatePurchaseOrderFromProcurementRequirementsAction
{
    use MatchesActorTenant;

    public function __construct(
        private readonly PurchaseOrderNumberGenerator $numbers,
        private readonly OrderProcurementAction $orderProcurement,
        private readonly DeriveSalesOrderProcurementRequirementsAction $deriveRequirements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $requirementIds
     * @param  array{expected_delivery_date?: ?string, notes?: ?string, quantities?: array<int|string, string|int|float>, sales_order_id?: ?int}  $data
     */
    public function execute(User $actor, Organization $organization, Supplier $supplier, array $requirementIds, array $data = [], ?string $clientOperationId = null): PurchaseOrder
    {
        $requirementIds = array_values(array_unique(array_map('intval', $requirementIds)));
        if ($requirementIds === []) {
            throw ValidationException::withMessages(['requirement_ids' => 'Sélectionnez au moins un besoin d’approvisionnement.']);
        }
        $this->authorize($actor, $organization);
        abort_unless((int) $supplier->organization_id === (int) $organization->getKey(), 404);

        return DB::transaction(function () use ($actor, $organization, $supplier, $requirementIds, $data, $clientOperationId) {
            if ($clientOperationId !== null) {
                $existing = PurchaseOrder::query()->where('organization_id', $organization->getKey())
                    ->where('client_operation_id', $clientOperationId)->first();
                if ($existing) {
                    return $existing; // retried submit
                }
            }

            $requirements = SalesOrderProcurement::query()->where('organization_id', $organization->getKey())
                ->whereKey($requirementIds)
                ->when(isset($data['sales_order_id']), fn ($query) => $query->where('sales_order_id', (int) $data['sales_order_id']))
                ->orderBy('id')->lockForUpdate()->get();
            if ($requirements->count() !== count($requirementIds)) {
                throw (new ModelNotFoundException)->setModel(SalesOrderProcurement::class, $requirementIds);
            }

            $storeIds = $requirements->pluck('store_id')->unique();
            if ($storeIds->count() !== 1) {
                throw ValidationException::withMessages(['requirement_ids' => 'Une commande fournisseur ne couvre qu’un seul magasin.']);
            }
            $orders = SalesOrder::query()->where('organization_id', $organization->getKey())
                ->whereKey($requirements->pluck('sales_order_id')->unique()->all())
                ->with('store')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $store = $orders->first()?->store;
            // Store access is proven like every store-scoped action: the web active
            // store, or the MCP connection (whose caller checks store membership).
            abort_unless($store !== null && $this->actorTenantMatches($actor, $organization, $store)
                && $actor->storeMemberships()->where('store_id', $store->getKey())->exists(), 403);

            $supplier = Supplier::query()->where('organization_id', $organization->getKey())->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            if (! $supplier->active) {
                throw ValidationException::withMessages(['supplier_id' => 'Ce fournisseur est inactif.']);
            }

            $ordered = SalesOrderProcurement::activePurchaseOrderQuantities((int) $organization->getKey(), $requirementIds, lock: true);
            $prepared = [];
            foreach ($requirements as $requirement) {
                $number = $requirement->procurement_number;
                if ((int) $requirement->supplier_id !== (int) $supplier->getKey()) {
                    throw ValidationException::withMessages(['requirement_ids' => "Le besoin {$number} est confirmé auprès d’un autre fournisseur."]);
                }
                if ($requirement->status === SupplierProcurementStatus::Ordered) {
                    throw ValidationException::withMessages(['requirement_ids' => "Le besoin {$number} est déjà commandé au fournisseur."]);
                }
                if ($requirement->status !== SupplierProcurementStatus::SupplierConfirmed) {
                    throw ValidationException::withMessages(['requirement_ids' => "Le besoin {$number} n’est pas ouvert à la commande (disponibilité fournisseur non confirmée, annulé ou déjà traité)."]);
                }
                $order = $orders->get($requirement->sales_order_id);
                if ($order === null || ! in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true)) {
                    throw ValidationException::withMessages(['requirement_ids' => 'Une commande client annulée ne peut plus être approvisionnée.']);
                }
                $line = $requirement->sales_order_line_id === null ? null : SalesOrderLine::query()->where('organization_id', $organization->getKey())
                    ->where('sales_order_id', $order->getKey())->whereKey($requirement->sales_order_line_id)->first();
                if ($line === null) {
                    throw ValidationException::withMessages(['requirement_ids' => "La ligne de commande du besoin {$number} n’existe plus."]);
                }

                $remaining = Decimal::subtract((string) $requirement->quantity, $ordered[$requirement->getKey()] ?? '0.0000');
                if (Decimal::compare($remaining, '0') <= 0) {
                    throw ValidationException::withMessages(['requirement_ids' => "Le besoin {$number} est déjà entièrement couvert par une commande fournisseur."]);
                }
                $requested = $data['quantities'][$requirement->getKey()] ?? null;
                $quantity = $requested === null || $requested === '' ? $remaining : Decimal::normalize((string) $requested);
                if (Decimal::compare($quantity, '0') <= 0) {
                    throw ValidationException::withMessages(["quantities.{$requirement->getKey()}" => 'La quantité commandée doit être positive.']);
                }
                if (Decimal::compare($quantity, $remaining) > 0) {
                    throw ValidationException::withMessages(["quantities.{$requirement->getKey()}" => "Il ne reste que {$remaining} à commander pour le besoin {$number}."]);
                }
                $prepared[] = [$requirement, $order, $line, $quantity];
            }

            $first = $orders->first();
            $purchaseOrder = new PurchaseOrder;
            $purchaseOrder->organization_id = $organization->getKey();
            $purchaseOrder->store_id = $store->getKey();
            $purchaseOrder->supplier_id = $supplier->getKey();
            $purchaseOrder->purchase_order_number = $this->numbers->next($organization);
            // Placing the order is the user's explicit act: created directly as ordered.
            $purchaseOrder->status = PurchaseOrderStatus::Ordered;
            $purchaseOrder->currency_code = $first->currency_code;
            $purchaseOrder->order_date = BusinessTime::today($organization);
            $purchaseOrder->expected_delivery_date = $data['expected_delivery_date'] ?? null;
            $purchaseOrder->notes = $data['notes'] ?? null;
            $purchaseOrder->client_operation_id = $clientOperationId;
            $purchaseOrder->ordered_at = now();
            $purchaseOrder->created_by_user_id = $actor->getKey();
            $purchaseOrder->ordered_by_user_id = $actor->getKey();
            $purchaseOrder->save();

            foreach ($prepared as $position => [$requirement, $order, $line, $quantity]) {
                $poLine = new PurchaseOrderLine;
                $poLine->organization_id = $organization->getKey();
                $poLine->purchase_order_id = $purchaseOrder->getKey();
                $poLine->sales_order_procurement_id = $requirement->getKey();
                $poLine->sales_order_id = $order->getKey();
                $poLine->sales_order_line_id = $line->getKey();
                $poLine->product_variant_id = $requirement->product_variant_id;
                $poLine->position = $position + 1;
                $poLine->quantity = $quantity;
                $poLine->unit_price = $requirement->supplier_unit_cost;
                $poLine->save();
            }

            $context = ['purchase_order_number' => $purchaseOrder->purchase_order_number, 'supplier_id' => $supplier->getKey(), 'supplier_name' => $supplier->name];
            $lines = array_map(fn (array $row) => [
                'procurement_id' => $row[0]->getKey(), 'procurement_number' => $row[0]->procurement_number,
                'sales_order_id' => $row[1]->getKey(), 'sales_order_number' => $row[1]->order_number,
                'sales_order_line_id' => $row[2]->getKey(), 'quantity' => $row[3],
            ], $prepared);
            $this->audit->record('purchase_order.created', $actor, $organization, $store, $purchaseOrder, newValues: [
                ...$context, 'status' => PurchaseOrderStatus::Ordered->value, 'expected_delivery_date' => $purchaseOrder->expected_delivery_date?->toDateString(), 'lines' => $lines,
            ]);
            $this->audit->record('purchase_order.ordered', $actor, $organization, $store, $purchaseOrder, oldValues: ['status' => PurchaseOrderStatus::Draft->value], newValues: [
                ...$context, 'status' => PurchaseOrderStatus::Ordered->value, 'ordered_at' => $purchaseOrder->ordered_at->toIso8601String(),
            ]);

            // Requirements whose whole quantity is now on purchase orders are ordered.
            foreach ($prepared as [$requirement, , , $quantity]) {
                $onOrders = Decimal::add($ordered[$requirement->getKey()] ?? '0.0000', $quantity);
                if (Decimal::compare($onOrders, (string) $requirement->quantity) >= 0) {
                    $this->orderProcurement->execute($actor, $requirement, array_filter([
                        'expected_at' => $purchaseOrder->expected_delivery_date?->toDateString(),
                        'purchase_order_id' => $purchaseOrder->getKey(),
                        'purchase_order_number' => $purchaseOrder->purchase_order_number,
                    ]));
                }
            }

            return $purchaseOrder->load('lines');
        });
    }

    /**
     * "Créer la commande fournisseur" from one Sales Order: every open requirement
     * of that supplier on that order. A requirement still to be derived from the
     * converted quotation (an order converted before requirements existed) is
     * created first, under the order lock, with the same derivation rules.
     *
     * @param  array{expected_delivery_date?: ?string, notes?: ?string}  $data
     */
    public function forSalesOrder(User $actor, SalesOrder $order, Supplier $supplier, array $data = [], ?string $clientOperationId = null): PurchaseOrder
    {
        $organization = $order->organization;
        $this->authorize($actor, $organization);

        return DB::transaction(function () use ($actor, $order, $organization, $supplier, $data, $clientOperationId) {
            if ($clientOperationId !== null && ($existing = PurchaseOrder::query()->where('organization_id', $organization->getKey())
                ->where('client_operation_id', $clientOperationId)->first())) {
                return $existing; // retried submit
            }
            $order = SalesOrder::query()->where('organization_id', $organization->getKey())->whereKey($order->getKey())
                ->with('store')->lockForUpdate()->firstOrFail();
            abort_unless($this->actorTenantMatches($actor, $organization, $order->store)
                && $actor->storeMemberships()->where('store_id', $order->store_id)->exists(), 403);

            $this->deriveRequirements->execute($actor, $order);

            $requirements = SalesOrderProcurement::query()->where('organization_id', $organization->getKey())
                ->where('sales_order_id', $order->getKey())->where('supplier_id', $supplier->getKey())
                ->where('status', SupplierProcurementStatus::SupplierConfirmed->value)
                ->orderBy('id')->get(['id', 'quantity']);
            $onOrders = SalesOrderProcurement::activePurchaseOrderQuantities((int) $organization->getKey(), $requirements->modelKeys());
            $open = $requirements->filter(fn (SalesOrderProcurement $r) => Decimal::compare((string) $r->quantity, $onOrders[$r->getKey()] ?? '0.0000') > 0)
                ->map(fn (SalesOrderProcurement $r) => (int) $r->getKey())->values()->all();
            if ($open === []) {
                throw ValidationException::withMessages(['supplier_id' => 'Aucun besoin à commander auprès de ce fournisseur sur cette commande.']);
            }

            return $this->execute($actor, $organization, $supplier, $open, [...$data, 'sales_order_id' => $order->getKey()], $clientOperationId);
        });
    }

    private function authorize(User $actor, Organization $organization): void
    {
        abort_unless(
            $this->actorOrganizationMatches($actor, $organization)
            && $organization->status === 'active'
            && $actor->organizationMemberships()->where('organization_id', $organization->getKey())->where('status', 'active')->exists()
            && $actor->hasPermission($organization, 'procurement.manage'),
            403
        );
    }
}
