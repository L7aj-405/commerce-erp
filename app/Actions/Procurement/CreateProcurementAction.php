<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProcurementNumberGenerator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raise a supplier special-order requirement for one catalogue line of a DRAFT
 * customer Order whose quantity company stock cannot fully cover. Creates no
 * inventory effect and no second Sales Order line — the procured quantity is the
 * SUPPLIER_ORDER-sourced portion of the existing line.
 */
class CreateProcurementAction
{
    use AuthorizesProcurementAction;

    public function __construct(
        private readonly ProcurementNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, SalesOrderLine $line, Supplier $supplier, array $data): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $order->organization, 'procurement.manage');

        return DB::transaction(function () use ($actor, $order, $line, $supplier, $data) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($order->organization_id === $actor->active_organization_id, 404);

            if ($order->status !== SalesOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'order' => 'Un approvisionnement fournisseur se prépare sur une commande brouillon, avant confirmation.',
                ]);
            }

            $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)
                ->where('sales_order_id', $order->getKey())->whereKey($line->getKey())->firstOrFail();
            if ($line->line_type !== SalesOrderLineType::Catalog || $line->product_variant_id === null) {
                throw ValidationException::withMessages(['line' => 'Seule une ligne catalogue peut être approvisionnée.']);
            }

            abort_unless($supplier->organization_id === $order->organization_id, 404);
            if (! $supplier->active) {
                throw ValidationException::withMessages(['supplier_id' => 'Ce fournisseur est inactif.']);
            }

            $quantity = Decimal::normalize((string) $data['quantity']);
            if (Decimal::compare($quantity, '0') <= 0) {
                throw ValidationException::withMessages(['quantity' => 'La quantité doit être positive.']);
            }

            // At most one live procurement per line in V1 (§23 changes the
            // supplier in place rather than stacking rows).
            $existing = SalesOrderProcurement::query()
                ->where('organization_id', $order->organization_id)
                ->where('sales_order_line_id', $line->getKey())
                ->whereNotIn('status', [SupplierProcurementStatus::Cancelled->value, SupplierProcurementStatus::Unavailable->value])
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages([
                    'line' => 'Un approvisionnement est déjà en cours pour cette ligne. Modifiez-le plutôt que d’en créer un second.',
                ]);
            }

            if (Decimal::compare($quantity, (string) $line->quantity) > 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'La quantité à approvisionner ne peut pas dépasser la quantité de la ligne.',
                ]);
            }

            $procurement = new SalesOrderProcurement;
            $procurement->organization_id = $order->organization_id;
            $procurement->store_id = $order->store_id;
            $procurement->procurement_number = $this->numbers->next($order->organization);
            $procurement->supplier_id = $supplier->getKey();
            $procurement->sales_order_id = $order->getKey();
            $procurement->sales_order_line_id = $line->getKey();
            $procurement->product_variant_id = $line->product_variant_id;
            $procurement->quantity = $quantity;
            $procurement->status = SupplierProcurementStatus::PendingSupplier;
            $procurement->supplier_availability_status = SupplierAvailabilityStatus::PendingConfirmation;
            $procurement->supplier_reference = $data['supplier_reference'] ?? null;
            $procurement->expected_at = $data['expected_at'] ?? null;
            $procurement->notes = $data['notes'] ?? null;
            $procurement->created_by_user_id = $actor->getKey();
            $procurement->save();

            $this->audit->record('procurement.created', $actor, $order->organization, $order->store, $procurement, newValues: [
                'procurement_number' => $procurement->procurement_number,
                'sales_order_id' => $order->getKey(),
                'sales_order_number' => $order->order_number,
                'sales_order_line_id' => $line->getKey(),
                'supplier_id' => $supplier->getKey(),
                'supplier_name' => $supplier->name,
                'product_variant_id' => $line->product_variant_id,
                'quantity' => $quantity,
            ]);

            return $procurement;
        });
    }
}
