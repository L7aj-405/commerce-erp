<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\TransferRequestStatus;
use App\Models\SalesOrder;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\TransferReceiptEarmarker;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * shipped → received.
 *
 * THIS is the only step with an inventory effect. In one transaction, with the
 * customer Order and the request locked:
 *
 *   1. free the Order's active hold at the SOURCE for the ORDER_FULFILLMENT
 *      quantity (see {@see TransferReceiptEarmarker});
 *   2. run the existing quantity-conserving StockTransfer ledger action
 *      (source −Q, destination +Q, paired immutable movements);
 *   3. hold that quantity for the Order at the DESTINATION;
 *   4. MINIMUM_REPLENISHMENT / MANUAL quantities simply land as free stock.
 *
 * Nothing is created or destroyed; on-hand stock is conserved company-wide.
 * The linked `StockTransfer` (the "completed movement") is recorded and linked.
 */
class ReceiveTransferRequestAction
{
    use AuthorizesInventoryAction;

    public function __construct(
        private readonly CreateStockTransferAction $stockTransfers,
        private readonly TransferReceiptEarmarker $earmarks,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, TransferRequest $request): TransferRequest
    {
        $this->authorizeInventory($actor, $request->organization, 'inventory.transfer_requests.receive');

        $orderId = $request->sales_order_id;

        return DB::transaction(function () use ($actor, $request, $orderId) {
            // Order first, then the request: the lock order of cancellation, correction and shipping.
            $order = $orderId === null ? null : SalesOrder::query()->where('organization_id', $request->organization_id)
                ->whereKey($orderId)->lockForUpdate()->first();
            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()
                ->with(['organization', 'lines.productVariant', 'sourceWarehouse', 'destinationWarehouse'])
                ->firstOrFail();

            if ($request->status !== TransferRequestStatus::Shipped) {
                throw ValidationException::withMessages(['status' => __('transfer_receipt.not_shipped')]);
            }
            if ($request->sales_order_id !== $orderId) {
                throw ValidationException::withMessages(['status' => __('transfer_receipt.changed')]);
            }

            $source = $request->sourceWarehouse;
            $destination = $request->destinationWarehouse;
            $organization = $request->organization;

            // --- 1. free the Order's SOURCE hold for the moved quantity ---
            $plan = $this->earmarks->plan($actor, $request, $order);

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

            try {
                $stockTransfer = $this->stockTransfers->execute(
                    $actor, $organization, $source, $destination, $moveLines,
                    "Réception demande {$request->request_number}",
                    'inventory.transfer_requests.receive',
                    respectTransferPromises: false,
                );
            } catch (ValidationException $exception) {
                if (! collect($exception->errors())->keys()->contains(fn (string $key) => str_starts_with($key, 'lines.'))) {
                    throw $exception;
                }
                throw ValidationException::withMessages([
                    'quantity' => __('transfer_receipt.insufficient_source_stock', ['warehouse' => $source->name]),
                ]);
            }

            // --- 3. hold the arrived Order quantity at the destination ---
            $this->earmarks->apply($actor, $request, $order, $plan);

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
