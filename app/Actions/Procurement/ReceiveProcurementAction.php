<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\ProcurementReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\TransferRequest;
use App\Models\TransferRequestLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceLocker;
use App\Services\InventoryReservationManager;
use App\Services\TransferRequestNumberGenerator;
use App\Support\BusinessTime;
use App\Support\Decimal;
use App\Support\InventoryQuantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier goods physically arrive — but for a specific customer Order. This is a
 * cross-dock receipt, never free general stock. The canonical (and only) goods
 * receipt of the procurement domain, full or partial.
 *
 * In ONE transaction:
 *   1. real `inventory_movements` row (type `supplier_receipt`) at the chosen
 *      receiving warehouse — on_hand rises by the received quantity;
 *   2. the SAME quantity is reserved for the linked Sales Order line — reserved
 *      rises by the same amount, so free availability is unchanged
 *      (Received=Q, Reserved for SO=Q, Available general +0):
 *        - confirmed order: on its allocation at that warehouse (as before);
 *        - draft order: on one reservation per requirement and warehouse,
 *          referenced by the requirement, which confirmation adopts into the
 *          line's allocation (never rebuilt, never reserved twice);
 *   3. a `procurement_receipts` row records the receipt (date, supplier delivery
 *      reference, purchase order line, movement, reservation);
 *   4. received quantities advance on the purchase order line(s), the
 *      requirement (partially_received → received) and the purchase order;
 *   5. on a confirmed order, if the goods landed away from the Order's
 *      operational warehouse, an internal Transfer Request carries them onward.
 *
 * The quantity defaults to everything still expected and can never exceed it:
 * the open quantity of the requirement's active purchase order lines, or — for a
 * requirement ordered without a purchase order — its quantity not yet received.
 * A client operation id makes a retried submit a no-op; an expected received
 * quantity rejects a receipt prepared on stale figures.
 */
class ReceiveProcurementAction
{
    use AuthorizesProcurementAction;

    public function __construct(
        private readonly InventoryBalanceLocker $balances,
        private readonly InventoryReservationManager $reservations,
        private readonly TransferRequestNumberGenerator $transferNumbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{quantity?: mixed, purchase_order_line_id?: ?int, received_on?: ?string, supplier_delivery_reference?: ?string,
     *     notes?: ?string, client_operation_id?: ?string, expected_received_quantity?: mixed} $data
     */
    public function execute(User $actor, SalesOrderProcurement $procurement, Warehouse $receivingWarehouse, array $data = []): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $procurement->organization, 'procurement.receive');
        $procurement->loadMissing('store');
        abort_unless($this->actorTenantMatches($actor, $procurement->organization, $procurement->store)
            && $actor->storeMemberships()->where('store_id', $procurement->store_id)->exists(), 403);

        return DB::transaction(function () use ($actor, $procurement, $receivingWarehouse, $data) {
            $organization = $procurement->organization;
            $operationId = $data['client_operation_id'] ?? null;
            if ($operationId !== null && ProcurementReceipt::query()->where('organization_id', $organization->getKey())
                ->where('client_operation_id', $operationId)->exists()) {
                return $procurement->fresh(['receivingWarehouse', 'transferRequest', 'supplier']); // retried submit
            }

            $procurement = SalesOrderProcurement::query()
                ->where('organization_id', $procurement->organization_id)
                ->whereKey($procurement->getKey())->lockForUpdate()
                ->with(['organization', 'store', 'productVariant', 'supplier'])
                ->firstOrFail();
            $explicitQuantity = isset($data['quantity']) && $data['quantity'] !== '';

            if ($procurement->status === SupplierProcurementStatus::Received && ! $explicitQuantity && empty($data['purchase_order_line_id'])) {
                return $procurement; // idempotent — a repeated "Confirmer réception"
            }
            if (! $procurement->status->isReceivable()) {
                throw ValidationException::withMessages([
                    'status' => 'Seul un approvisionnement commandé au fournisseur peut être réceptionné.',
                ]);
            }
            if (isset($data['expected_received_quantity']) && $data['expected_received_quantity'] !== ''
                && Decimal::compare(Decimal::normalize((string) $data['expected_received_quantity']), (string) $procurement->received_quantity) !== 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Une autre réception a été enregistrée entre-temps. Rechargez la page avant de réceptionner.',
                ]);
            }

            abort_unless($receivingWarehouse->organization_id === $organization->getKey(), 404);
            $receivingWarehouse = Warehouse::query()->where('organization_id', $organization->getKey())->whereKey($receivingWarehouse->getKey())->firstOrFail();
            if ($receivingWarehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages(['receiving_warehouse_id' => 'Entrepôt de réception inactif.']);
            }

            // What may still arrive, per purchase order line (null = ordered without a purchase order).
            $slots = $this->openSlots($procurement, isset($data['purchase_order_line_id']) ? (int) $data['purchase_order_line_id'] : null);
            $open = $slots->reduce(fn (string $sum, array $slot) => Decimal::add($sum, $slot['open']), '0.0000');
            if (Decimal::compare($open, '0') <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Rien ne reste à réceptionner pour cet approvisionnement.']);
            }
            $quantity = $explicitQuantity ? InventoryQuantity::normalize((string) $data['quantity']) : $open;
            if (Decimal::compare($quantity, '0') <= 0) {
                throw ValidationException::withMessages(['quantity' => 'La quantité reçue doit être positive.']);
            }
            if (Decimal::compare($quantity, $open) > 0) {
                throw ValidationException::withMessages(['quantity' => "La quantité reçue dépasse la quantité attendue ({$open})."]);
            }
            $receivedOn = $data['received_on'] ?? null ?: BusinessTime::today($organization);
            if ($receivedOn > BusinessTime::today($organization)) {
                throw ValidationException::withMessages(['received_on' => 'La date de réception ne peut pas être dans le futur.']);
            }

            $variant = $procurement->productVariant;
            $order = SalesOrder::query()->where('organization_id', $organization->getKey())
                ->whereKey($procurement->sales_order_id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true)) {
                throw ValidationException::withMessages(['status' => 'La commande client n’est plus ouverte : réception impossible.']);
            }
            $line = SalesOrderLine::query()->where('organization_id', $organization->getKey())
                ->whereKey($procurement->sales_order_line_id)->lockForUpdate()->firstOrFail();

            // --- 1. real goods-in movement ----------------------------------
            $balance = $this->balances->lock($organization, $receivingWarehouse, $variant);
            $before = $balance->on_hand;
            $after = InventoryQuantity::add($before, $quantity);

            $movement = new InventoryMovement;
            $movement->organization_id = $organization->getKey();
            $movement->warehouse_id = $receivingWarehouse->getKey();
            $movement->product_variant_id = $variant->getKey();
            $movement->movement_type = InventoryMovementType::SupplierReceipt;
            $movement->quantity = $quantity;
            $movement->quantity_before = $before;
            $movement->quantity_after = $after;
            $movement->reference_type = SalesOrderProcurement::class;
            $movement->reference_id = $procurement->getKey();
            $movement->reference = $order->order_number;
            $movement->reason = "Réception fournisseur {$procurement->procurement_number}";
            $movement->performed_by_user_id = $actor->getKey();
            $movement->metadata = array_filter([
                'supplier_id' => $procurement->supplier_id, 'sales_order_id' => $order->getKey(),
                'purchase_order_line_ids' => $slots->pluck('line.id')->filter()->values()->all() ?: null,
                'supplier_delivery_reference' => $data['supplier_delivery_reference'] ?? null,
            ], fn ($value) => $value !== null);
            $movement->save();

            $balance->on_hand = $after;
            $balance->save();

            // --- 2. earmark the SAME quantity for the Sales Order line -------
            [$reservation, $allocation] = $order->status === SalesOrderStatus::Confirmed
                ? $this->reserveOnAllocation($actor, $order, $line, $receivingWarehouse, $variant, $quantity)
                : [$this->reserveForDraft($actor, $order, $procurement, $receivingWarehouse, $variant, $quantity), null];

            // --- 3. receipt rows + received quantities ----------------------
            $remaining = $quantity;
            $purchaseOrderIds = [];
            foreach ($slots as $slot) {
                if (Decimal::compare($remaining, '0') <= 0) {
                    break;
                }
                $portion = Decimal::compare($slot['open'], $remaining) < 0 ? $slot['open'] : $remaining;
                $remaining = Decimal::subtract($remaining, $portion);

                $receipt = new ProcurementReceipt;
                $receipt->organization_id = $organization->getKey();
                $receipt->sales_order_procurement_id = $procurement->getKey();
                $receipt->purchase_order_line_id = $slot['line']?->getKey();
                $receipt->warehouse_id = $receivingWarehouse->getKey();
                $receipt->quantity = $portion;
                $receipt->received_on = $receivedOn;
                $receipt->supplier_delivery_reference = $data['supplier_delivery_reference'] ?? null;
                $receipt->notes = $data['notes'] ?? null;
                $receipt->inventory_movement_id = $movement->getKey();
                $receipt->inventory_reservation_id = $reservation->getKey();
                $receipt->sales_order_inventory_allocation_id = $allocation?->getKey();
                // Only the first row of one physical receipt carries its operation id.
                $receipt->client_operation_id = $operationId;
                $operationId = null;
                $receipt->received_by_user_id = $actor->getKey();
                $receipt->save();

                if ($slot['line'] !== null) {
                    $slot['line']->received_quantity = Decimal::add((string) $slot['line']->received_quantity, $portion);
                    $slot['line']->save();
                    $purchaseOrderIds[$slot['line']->purchase_order_id] = true;
                }
            }

            $procurement->received_quantity = Decimal::add((string) $procurement->received_quantity, $quantity);
            $complete = Decimal::compare((string) $procurement->received_quantity, (string) $procurement->quantity) >= 0;
            $procurement->status = $complete ? SupplierProcurementStatus::Received : SupplierProcurementStatus::PartiallyReceived;
            $procurement->received_at = now();
            $procurement->received_by_user_id = $actor->getKey();
            $procurement->receiving_warehouse_id = $receivingWarehouse->getKey();

            // --- 4. cross-dock onward if it landed at the wrong warehouse ---
            // Confirmed orders only: a draft has no operational stock position yet
            // (its company portion is allocated at confirmation, next to the goods).
            // The Order's operational warehouse is the POS Showroom for a POS
            // pickup order, otherwise wherever the rest of this line's company
            // stock is already reserved. If the goods arrived elsewhere, the
            // existing internal Transfer Request workflow carries them onward —
            // no second procurement record.
            $operationalWarehouseId = $order->status !== SalesOrderStatus::Confirmed ? 0 : ($order->source === SalesOrderSource::Pos && $order->pos_warehouse_id
                ? (int) $order->pos_warehouse_id
                : (int) SalesOrderInventoryAllocation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('sales_order_line_id', $line->getKey())
                    ->where('warehouse_id', '!=', $receivingWarehouse->getKey())
                    ->whereNotNull('inventory_reservation_id')
                    ->orderBy('id')
                    ->value('warehouse_id'));
            if ($operationalWarehouseId !== 0 && $operationalWarehouseId !== $receivingWarehouse->getKey()) {
                $request = $this->internalTransferFor($actor, $order, $receivingWarehouse->getKey(), $operationalWarehouseId);
                $this->upsertTransferLine($request, (int) $variant->getKey(), $quantity, (int) $line->getKey());
                $procurement->transfer_request_id = $request->getKey();

                $this->audit->record('transfer_request.created', $actor, $organization, auditable: $request, newValues: [
                    'request_number' => $request->request_number,
                    'source_warehouse_id' => $receivingWarehouse->getKey(),
                    'destination_warehouse_id' => $operationalWarehouseId,
                    'sales_order_id' => $order->getKey(),
                    'sales_order_number' => $order->order_number,
                    'reason' => TransferRequestReason::OrderFulfillment->value,
                    'origin' => 'supplier_procurement',
                ]);
            }

            $procurement->save();

            $this->audit->record($complete ? 'procurement.received' : 'procurement.partially_received', $actor, $organization, $procurement->store, $procurement, newValues: [
                'procurement_number' => $procurement->procurement_number,
                'sales_order_id' => $order->getKey(),
                'sales_order_number' => $order->order_number,
                'supplier_id' => $procurement->supplier_id,
                'supplier_name' => $procurement->supplier?->name,
                'product_variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'received_quantity' => $procurement->received_quantity,
                'requirement_quantity' => $procurement->quantity,
                'received_on' => $receivedOn,
                'supplier_delivery_reference' => $data['supplier_delivery_reference'] ?? null,
                'receiving_warehouse_id' => $receivingWarehouse->getKey(),
                'movement_id' => $movement->getKey(),
                'reservation_id' => $reservation->getKey(),
                'transfer_request_id' => $procurement->transfer_request_id,
            ]);

            foreach (array_keys($purchaseOrderIds) as $purchaseOrderId) {
                $this->refreshPurchaseOrderStatus($actor, (int) $organization->getKey(), $purchaseOrderId);
            }

            return $procurement->load(['receivingWarehouse', 'transferRequest', 'supplier']);
        });
    }

    /**
     * Open receivable quantity per active purchase order line of the requirement
     * (FIFO), or one slot without a line for a requirement ordered without one.
     *
     * @return Collection<int, array{line: ?PurchaseOrderLine, open: string}>
     */
    private function openSlots(SalesOrderProcurement $procurement, ?int $purchaseOrderLineId): Collection
    {
        $lines = PurchaseOrderLine::query()->where('organization_id', $procurement->organization_id)
            ->where('sales_order_procurement_id', $procurement->getKey())
            ->whereHas('purchaseOrder', fn ($query) => $query->where('status', '!=', PurchaseOrderStatus::Cancelled->value))
            ->orderBy('id')->lockForUpdate()->get();

        if ($purchaseOrderLineId !== null) {
            $line = $lines->firstWhere('id', $purchaseOrderLineId);
            if ($line === null) {
                // Not a line of this requirement, or its purchase order is cancelled.
                throw ValidationException::withMessages(['purchase_order_line_id' => 'Cette ligne de commande fournisseur n’est pas ouverte à la réception pour ce besoin.']);
            }
            $lines = collect([$line]);
        }

        if ($lines->isEmpty()) {
            if ($procurement->status === SupplierProcurementStatus::SupplierConfirmed) {
                throw ValidationException::withMessages(['status' => 'Seul un approvisionnement commandé au fournisseur peut être réceptionné.']);
            }

            return collect([['line' => null, 'open' => Decimal::subtract((string) $procurement->quantity, (string) $procurement->received_quantity)]]);
        }

        PurchaseOrder::query()->where('organization_id', $procurement->organization_id)
            ->whereKey($lines->pluck('purchase_order_id')->unique()->all())->lockForUpdate()->get();

        return $lines->map(fn (PurchaseOrderLine $line) => ['line' => $line, 'open' => Decimal::subtract((string) $line->quantity, (string) $line->received_quantity)])
            ->filter(fn (array $slot) => Decimal::compare($slot['open'], '0') > 0)->values();
    }

    /**
     * Confirmed order: the line's allocation at the receiving warehouse carries
     * the received quantity (its reservation increased, or a new one).
     *
     * @return array{InventoryReservation, SalesOrderInventoryAllocation}
     */
    private function reserveOnAllocation(User $actor, SalesOrder $order, SalesOrderLine $line, Warehouse $warehouse, $variant, string $quantity): array
    {
        $organization = $order->organization;
        $allocation = SalesOrderInventoryAllocation::query()
            ->where('organization_id', $organization->getKey())
            ->where('sales_order_line_id', $line->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->lockForUpdate()
            ->first();

        if ($allocation && $allocation->inventory_reservation_id !== null) {
            $reservation = InventoryReservation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($allocation->inventory_reservation_id)->firstOrFail();
            if ($reservation->status !== InventoryReservationStatus::Active) {
                throw ValidationException::withMessages(['reservation' => 'La réservation liée n’est pas active.']);
            }
            $reservation = $this->reservations->increase($actor, $organization, $reservation, $quantity);
            $allocation->quantity = InventoryQuantity::add($allocation->quantity, $quantity);
            $allocation->save();

            return [$reservation, $allocation];
        }

        if (! $allocation) {
            $allocation = new SalesOrderInventoryAllocation;
            $allocation->organization_id = $organization->getKey();
            $allocation->sales_order_line_id = $line->getKey();
            $allocation->warehouse_id = $warehouse->getKey();
            $allocation->quantity = $quantity;
        } else {
            $allocation->quantity = InventoryQuantity::add($allocation->quantity, $quantity);
        }
        $allocation->save();
        $reservation = $this->reservations->reserve(
            $actor, $organization, $warehouse, $variant, $quantity,
            SalesOrderInventoryAllocation::class, $allocation->getKey(), $order->order_number,
        );
        $allocation->inventory_reservation_id = $reservation->getKey();
        $allocation->save();

        return [$reservation, $allocation];
    }

    /**
     * Draft order: its allocations are provisional (rebuilt at confirmation), so
     * the received goods get their own reservation, one per requirement and
     * warehouse, referenced by the requirement. ConfirmSalesOrderAction adopts it.
     */
    private function reserveForDraft(User $actor, SalesOrder $order, SalesOrderProcurement $procurement, Warehouse $warehouse, $variant, string $quantity): InventoryReservation
    {
        $organization = $order->organization;
        $existing = InventoryReservation::query()->where('organization_id', $organization->getKey())
            ->where('warehouse_id', $warehouse->getKey())->where('product_variant_id', $variant->getKey())
            ->where('reference_type', SalesOrderProcurement::class)->where('reference_id', $procurement->getKey())
            ->where('status', InventoryReservationStatus::Active->value)->lockForUpdate()->first();
        if ($existing) {
            return $this->reservations->increase($actor, $organization, $existing, $quantity);
        }

        return $this->reservations->reserve(
            $actor, $organization, $warehouse, $variant, $quantity,
            SalesOrderProcurement::class, $procurement->getKey(), $order->order_number,
        );
    }

    /** ordered → partially_received → received, from the lines' received quantities. */
    private function refreshPurchaseOrderStatus(User $actor, int $organizationId, int $purchaseOrderId): void
    {
        $purchaseOrder = PurchaseOrder::query()->where('organization_id', $organizationId)->whereKey($purchaseOrderId)
            ->with(['lines', 'organization', 'store'])->lockForUpdate()->firstOrFail();
        $complete = $purchaseOrder->lines->every(fn (PurchaseOrderLine $line) => Decimal::compare((string) $line->received_quantity, (string) $line->quantity) >= 0);
        $status = $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived;
        if ($purchaseOrder->status === $status) {
            return;
        }
        $old = $purchaseOrder->status;
        $purchaseOrder->status = $status;
        $purchaseOrder->save();

        $this->audit->record($complete ? 'purchase_order.received' : 'purchase_order.partially_received', $actor, $purchaseOrder->organization, $purchaseOrder->store, $purchaseOrder,
            oldValues: ['status' => $old->value],
            newValues: ['purchase_order_number' => $purchaseOrder->purchase_order_number, 'status' => $status->value]);
    }

    private function internalTransferFor(User $actor, SalesOrder $order, int $sourceId, int $destinationId): TransferRequest
    {
        $request = TransferRequest::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->where('source_warehouse_id', $sourceId)
            ->lockForUpdate()
            ->first();
        if ($request) {
            return $request;
        }

        $request = new TransferRequest;
        $request->organization_id = $order->organization_id;
        $request->request_number = $this->transferNumbers->next($order->organization);
        $request->source_warehouse_id = $sourceId;
        $request->destination_warehouse_id = $destinationId;
        $request->status = TransferRequestStatus::Requested;
        $request->sales_order_id = $order->getKey();
        $request->requested_by_user_id = $actor->getKey();
        $request->requested_at = now();
        $request->save();

        return $request;
    }

    private function upsertTransferLine(TransferRequest $request, int $variantId, string $quantity, int $salesOrderLineId): void
    {
        $line = TransferRequestLine::query()
            ->where('organization_id', $request->organization_id)
            ->where('transfer_request_id', $request->getKey())
            ->where('product_variant_id', $variantId)
            ->where('reason', TransferRequestReason::OrderFulfillment->value)
            ->lockForUpdate()
            ->first();

        if ($line) {
            $line->quantity = InventoryQuantity::add($line->quantity, $quantity);
            $line->sales_order_line_id = $salesOrderLineId;
            $line->save();

            return;
        }

        $line = new TransferRequestLine;
        $line->organization_id = $request->organization_id;
        $line->transfer_request_id = $request->getKey();
        $line->product_variant_id = $variantId;
        $line->quantity = InventoryQuantity::normalize($quantity);
        $line->reason = TransferRequestReason::OrderFulfillment;
        $line->sales_order_line_id = $salesOrderLineId;
        $line->save();
    }
}
