<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\InventoryReservationStatus;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\InventoryReservation;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * shipped → received.
 *
 * THIS is the only step with an inventory effect. In one transaction:
 *
 *   1. release the customer Order's reservations that still sit at the SOURCE
 *      for the ORDER_FULFILLMENT quantity (freeing the on-hand it protects);
 *   2. run the existing quantity-conserving StockTransfer ledger action
 *      (source −Q, destination +Q, paired immutable movements);
 *   3. re-earmark that quantity for the Order at the DESTINATION — growing the
 *      Order's existing operational-warehouse reservation, or re-pointing the
 *      allocation there when it had none;
 *   4. MINIMUM_REPLENISHMENT / MANUAL quantities simply land as free stock.
 *
 * Nothing is created or destroyed; company-wide available stock is conserved.
 * The linked `StockTransfer` (the "completed movement") is recorded and linked.
 */
class ReceiveTransferRequestAction
{
    use AuthorizesInventoryAction;

    public function __construct(
        private readonly CreateStockTransferAction $stockTransfers,
        private readonly InventoryReservationManager $reservations,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, TransferRequest $request): TransferRequest
    {
        $this->authorizeInventory($actor, $request->organization, 'inventory.transfer_requests.receive');

        return DB::transaction(function () use ($actor, $request) {
            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()
                ->with(['organization', 'lines.productVariant', 'sourceWarehouse', 'destinationWarehouse'])
                ->firstOrFail();

            if ($request->status !== TransferRequestStatus::Shipped) {
                throw ValidationException::withMessages(['status' => 'Seule une demande expédiée peut être réceptionnée.']);
            }

            $source = $request->sourceWarehouse;
            $destination = $request->destinationWarehouse;
            $organization = $request->organization;

            // --- 1. release the Order's SOURCE reservations for the moved qty ---
            $earmark = []; // sales_order_line_id => qty to re-reserve at destination
            if ($request->sales_order_id !== null) {
                $variantIds = $request->lines
                    ->where('reason', TransferRequestReason::OrderFulfillment)
                    ->pluck('product_variant_id')->unique()->values()->all();

                $sourceAllocations = SalesOrderInventoryAllocation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('warehouse_id', $source->getKey())
                    ->whereHas('salesOrderLine', fn ($query) => $query
                        ->where('sales_order_id', $request->sales_order_id)
                        ->whereIn('product_variant_id', $variantIds ?: [0]))
                    ->with('salesOrderLine')
                    ->lockForUpdate()
                    ->get();

                foreach ($sourceAllocations as $allocation) {
                    if ($allocation->inventory_reservation_id !== null) {
                        $reservation = InventoryReservation::query()
                            ->where('organization_id', $organization->getKey())
                            ->whereKey($allocation->inventory_reservation_id)->first();
                        if ($reservation && $reservation->status === InventoryReservationStatus::Active) {
                            $this->reservations->release($actor, $organization, $reservation);
                        }
                    }
                    $key = (int) $allocation->sales_order_line_id;
                    $earmark[$key] = [
                        'qty' => InventoryQuantity::add($earmark[$key]['qty'] ?? InventoryQuantity::ZERO, $allocation->quantity),
                        'variant_id' => (int) $allocation->salesOrderLine->product_variant_id,
                        'source_allocation' => $allocation,
                    ];
                }
            }

            // --- 2. quantity-conserving physical ledger transfer ---
            $variants = $request->lines->pluck('productVariant', 'product_variant_id');
            $moveLines = $request->lines
                ->groupBy('product_variant_id')
                ->map(fn ($lines, $variantId) => [
                    'variant' => $variants[$variantId],
                    'quantity' => $lines->reduce(
                        fn (string $carry, $line) => InventoryQuantity::add($carry, $line->quantity),
                        InventoryQuantity::ZERO,
                    ),
                ])
                ->values()->all();

            $stockTransfer = $this->stockTransfers->execute(
                $actor, $organization, $source, $destination, $moveLines,
                "Réception demande {$request->request_number}",
                'inventory.transfer_requests.receive',
            );

            // --- 3. re-earmark the Order quantity at the destination ---
            foreach ($earmark as $salesOrderLineId => $data) {
                $existing = SalesOrderInventoryAllocation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('sales_order_line_id', $salesOrderLineId)
                    ->where('warehouse_id', $destination->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing && $existing->inventory_reservation_id !== null) {
                    $reservation = InventoryReservation::query()
                        ->where('organization_id', $organization->getKey())
                        ->whereKey($existing->inventory_reservation_id)->firstOrFail();
                    $this->reservations->increase($actor, $organization, $reservation, $data['qty']);
                    $existing->quantity = InventoryQuantity::add($existing->quantity, $data['qty']);
                    $existing->save();
                    $data['source_allocation']->delete();
                } else {
                    $allocation = $data['source_allocation'];
                    $allocation->warehouse_id = $destination->getKey();
                    $allocation->quantity = InventoryQuantity::normalize($data['qty']);
                    $allocation->save();
                    $reservation = $this->reservations->reserve(
                        $actor, $organization, $destination, $variants[$data['variant_id']],
                        $data['qty'], SalesOrderInventoryAllocation::class, $allocation->getKey(),
                        $request->sales_order_id ? (string) $request->sales_order_id : null,
                    );
                    $allocation->inventory_reservation_id = $reservation->getKey();
                    $allocation->save();
                }
            }

            // --- 4. close the request ---
            $request->status = TransferRequestStatus::Received;
            $request->received_by_user_id = $actor->getKey();
            $request->received_at = now();
            $request->stock_transfer_id = $stockTransfer->getKey();
            $request->save();

            $this->audit->record('transfer_request.received', $actor, $organization, auditable: $request, newValues: [
                'request_number' => $request->request_number,
                'stock_transfer_id' => $stockTransfer->getKey(),
                'stock_transfer_number' => $stockTransfer->transfer_number,
                'source_warehouse_id' => $source->getKey(),
                'destination_warehouse_id' => $destination->getKey(),
                'sales_order_id' => $request->sales_order_id,
            ]);

            return $request->load(['lines.productVariant', 'stockTransfer']);
        });
    }
}
