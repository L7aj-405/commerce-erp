<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SupplierProcurementStatus;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
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
use App\Support\Decimal;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier goods physically arrive — but for a specific customer Order. This is a
 * cross-dock receipt, never free general stock.
 *
 * In ONE transaction:
 *   1. real `inventory_movements` row (type `supplier_receipt`) at the chosen
 *      receiving warehouse — on_hand rises by the received quantity;
 *   2. a `sales_order_inventory_allocations` row + reservation earmark the SAME
 *      quantity for the linked Sales Order line — reserved rises by the same
 *      amount, so free availability is unchanged (Received=Q, Reserved for SO=Q,
 *      Available general +0);
 *   3. if the receiving warehouse is not the Order's operational Showroom, an
 *      internal Transfer Request (existing domain) is created / reused to carry
 *      the earmarked quantity onward — no second procurement record.
 *
 * V1 requires an exact, full receipt.
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

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrderProcurement $procurement, Warehouse $receivingWarehouse, array $data = []): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $procurement->organization, 'procurement.receive');

        return DB::transaction(function () use ($actor, $procurement, $receivingWarehouse, $data) {
            $procurement = SalesOrderProcurement::query()
                ->where('organization_id', $procurement->organization_id)
                ->whereKey($procurement->getKey())->lockForUpdate()
                ->with(['organization', 'store', 'productVariant', 'supplier'])
                ->firstOrFail();

            if ($procurement->status === SupplierProcurementStatus::Received) {
                return $procurement; // idempotent — a repeated "Confirmer réception"
            }
            if ($procurement->status !== SupplierProcurementStatus::Ordered) {
                throw ValidationException::withMessages([
                    'status' => 'Seul un approvisionnement commandé au fournisseur peut être réceptionné.',
                ]);
            }

            $organization = $procurement->organization;
            abort_unless($receivingWarehouse->organization_id === $organization->getKey(), 404);
            if ($receivingWarehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages(['receiving_warehouse_id' => 'Entrepôt de réception inactif.']);
            }

            $quantity = InventoryQuantity::normalize((string) $procurement->quantity);
            if (array_key_exists('quantity', $data) && $data['quantity'] !== null && $data['quantity'] !== '') {
                $received = InventoryQuantity::normalize((string) $data['quantity']);
                if (Decimal::compare($received, $quantity) !== 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'La V1 exige une réception complète, égale à la quantité commandée.',
                    ]);
                }
            }

            $variant = $procurement->productVariant;
            $order = SalesOrder::query()->where('organization_id', $organization->getKey())
                ->whereKey($procurement->sales_order_id)->lockForUpdate()->firstOrFail();
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
            $movement->metadata = ['supplier_id' => $procurement->supplier_id, 'sales_order_id' => $order->getKey()];
            $movement->save();

            $balance->on_hand = $after;
            $balance->save();

            // --- 2. earmark the SAME quantity for the Sales Order line -------
            $allocation = SalesOrderInventoryAllocation::query()
                ->where('organization_id', $organization->getKey())
                ->where('sales_order_line_id', $line->getKey())
                ->where('warehouse_id', $receivingWarehouse->getKey())
                ->lockForUpdate()
                ->first();

            if ($allocation && $allocation->inventory_reservation_id !== null) {
                $reservation = InventoryReservation::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($allocation->inventory_reservation_id)->firstOrFail();
                if ($reservation->status !== InventoryReservationStatus::Active) {
                    throw ValidationException::withMessages(['reservation' => 'La réservation liée n’est pas active.']);
                }
                $this->reservations->increase($actor, $organization, $reservation, $quantity);
                $allocation->quantity = InventoryQuantity::add($allocation->quantity, $quantity);
                $allocation->save();
            } else {
                if (! $allocation) {
                    $allocation = new SalesOrderInventoryAllocation;
                    $allocation->organization_id = $organization->getKey();
                    $allocation->sales_order_line_id = $line->getKey();
                    $allocation->warehouse_id = $receivingWarehouse->getKey();
                    $allocation->quantity = $quantity;
                    $allocation->save();
                } else {
                    $allocation->quantity = InventoryQuantity::add($allocation->quantity, $quantity);
                    $allocation->save();
                }
                $reservation = $this->reservations->reserve(
                    $actor, $organization, $receivingWarehouse, $variant, $quantity,
                    SalesOrderInventoryAllocation::class, $allocation->getKey(), $order->order_number,
                );
                $allocation->inventory_reservation_id = $reservation->getKey();
                $allocation->save();
            }

            // --- 3. close the procurement ----------------------------------
            $procurement->status = SupplierProcurementStatus::Received;
            $procurement->received_at = now();
            $procurement->received_by_user_id = $actor->getKey();
            $procurement->receiving_warehouse_id = $receivingWarehouse->getKey();

            // --- 4. cross-dock onward if it landed at the wrong warehouse ---
            // The Order's operational warehouse is the POS Showroom for a POS
            // pickup order, otherwise wherever the rest of this line's company
            // stock is already reserved. If the goods arrived elsewhere, the
            // existing internal Transfer Request workflow carries them onward —
            // no second procurement record.
            $operationalWarehouseId = $order->source === SalesOrderSource::Pos && $order->pos_warehouse_id
                ? (int) $order->pos_warehouse_id
                : (int) SalesOrderInventoryAllocation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('sales_order_line_id', $line->getKey())
                    ->where('warehouse_id', '!=', $receivingWarehouse->getKey())
                    ->whereNotNull('inventory_reservation_id')
                    ->orderBy('id')
                    ->value('warehouse_id');
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

            $this->audit->record('procurement.received', $actor, $organization, $procurement->store, $procurement, newValues: [
                'procurement_number' => $procurement->procurement_number,
                'sales_order_id' => $order->getKey(),
                'sales_order_number' => $order->order_number,
                'supplier_id' => $procurement->supplier_id,
                'supplier_name' => $procurement->supplier?->name,
                'product_variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'receiving_warehouse_id' => $receivingWarehouse->getKey(),
                'movement_id' => $movement->getKey(),
                'transfer_request_id' => $procurement->transfer_request_id,
            ]);

            return $procurement->load(['receivingWarehouse', 'transferRequest', 'supplier']);
        });
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
