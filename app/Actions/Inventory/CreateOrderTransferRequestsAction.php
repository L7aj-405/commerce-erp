<?php

namespace App\Actions\Inventory;

use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderSource;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\SalesOrder;
use App\Models\TransferRequest;
use App\Models\TransferRequestLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use App\Services\TransferRequestNumberGenerator;
use App\Support\InventoryQuantity;

/**
 * After a Showroom POS Order is confirmed, two INDEPENDENT things happen:
 *
 *  A. ORDER_FULFILLMENT — every allocation that sits in another warehouse
 *     becomes a Depot → Showroom transfer request (pickup orders only; a
 *     delivery order ships from the source directly, so no routing through the
 *     Showroom is required).
 *
 *  B. MINIMUM_REPLENISHMENT — for EVERY catalogue variant on the Order, the
 *     Showroom minimum-stock policy is re-evaluated against current projected
 *     availability (which already reflects this Order's reservations). This runs
 *     whether or not the Order needed any remote stock, and whether the Order is
 *     pickup or delivery: what matters is that the Order reduced Showroom
 *     availability, not the customer's fulfilment method.
 *
 * Where B's source matches A's, the two reasons land on the SAME request so the
 * "why 7?" breakdown stays visible. A Transfer Request is a logistics
 * instruction only — this creates NO inventory movement and reserves nothing
 * extra. Runs INSIDE the ConfirmSalesOrderAction transaction. Idempotent:
 * `(organization_id, sales_order_id, source_warehouse_id)` is unique and lines
 * are upserted, so a retried confirmation reuses rows.
 */
class CreateOrderTransferRequestsAction
{
    public function __construct(
        private readonly TransferRequestNumberGenerator $numbers,
        private readonly ShowroomReplenishmentPlanner $planner,
        private readonly AuditLogger $audit,
    ) {}

    public function createForConfirmedOrder(User $actor, SalesOrder $order): void
    {
        $showroomId = (int) $order->pos_warehouse_id;
        if ($order->source !== SalesOrderSource::Pos || $showroomId === 0) {
            return;
        }

        $order->loadMissing('lines.allocations');
        $isPickup = $order->pos_fulfillment_mode !== 'delivery';

        // remote source warehouse id => [ variant id => ['qty' => string, 'line_id' => int] ]
        $remote = [];
        // variant id => a source warehouse the order pulled it from (replenishment source hint)
        $remoteSourceByVariant = [];
        // every catalogue variant on the order — the replenishment candidate set
        $orderVariantIds = [];

        foreach ($order->lines as $line) {
            if ($line->line_type !== SalesOrderLineType::Catalog || $line->product_variant_id === null) {
                continue;
            }
            $variantId = (int) $line->product_variant_id;
            $orderVariantIds[$variantId] = $variantId;

            foreach ($line->allocations as $allocation) {
                $sourceId = (int) $allocation->warehouse_id;
                if ($sourceId === $showroomId) {
                    continue;
                }
                $current = $remote[$sourceId][$variantId]['qty'] ?? InventoryQuantity::ZERO;
                // Same variant may appear on several order lines from the same
                // source: aggregate the physical demand here (traceability to
                // each order line + reservation lives in
                // sales_order_inventory_allocations, which ReceiveTransferRequestAction
                // drives off directly).
                $remote[$sourceId][$variantId] = [
                    'qty' => InventoryQuantity::add($current, $allocation->quantity),
                    'line_id' => $line->getKey(),
                ];
                $remoteSourceByVariant[$variantId] ??= $sourceId;
            }
        }

        // --- A. ORDER_FULFILLMENT (pickup only) --------------------------------
        if ($isPickup) {
            foreach ($remote as $sourceId => $variants) {
                $request = $this->requestFor($actor, $order, (int) $sourceId, $showroomId);

                foreach ($variants as $variantId => $data) {
                    $this->upsertLine($request, (int) $variantId, $data['qty'], TransferRequestReason::OrderFulfillment, $data['line_id']);
                }

                $this->audit->record('transfer_request.created', $actor, $order->organization, auditable: $request, newValues: [
                    'request_number' => $request->request_number,
                    'source_warehouse_id' => (int) $sourceId,
                    'destination_warehouse_id' => $showroomId,
                    'sales_order_id' => $order->getKey(),
                    'sales_order_number' => $order->order_number,
                    'reason' => TransferRequestReason::OrderFulfillment->value,
                ]);
            }
        }

        // --- B. MINIMUM_REPLENISHMENT (always, for every order variant) -------
        $this->appendReplenishment($actor, $order, $showroomId, array_values($orderVariantIds), $remoteSourceByVariant);
    }

    /**
     * @param  list<int>  $candidateVariantIds
     * @param  array<int,int>  $preferredSourceByVariant
     */
    private function appendReplenishment(User $actor, SalesOrder $order, int $showroomId, array $candidateVariantIds, array $preferredSourceByVariant): void
    {
        if ($candidateVariantIds === [] || ! $this->planner->isEnabled((int) $order->organization_id, $showroomId)) {
            return;
        }

        $deficits = $this->planner->deficits((int) $order->organization_id, $showroomId, $candidateVariantIds);

        foreach ($deficits as $variantId => $deficit) {
            $sources = $this->planner->sourcesFor(
                (int) $order->organization_id,
                $showroomId,
                (int) $variantId,
                $deficit,
                preferredSourceId: $preferredSourceByVariant[$variantId] ?? null,
            );

            foreach ($sources as $source) {
                $request = $this->requestFor($actor, $order, (int) $source['warehouse_id'], $showroomId);
                $this->upsertLine($request, (int) $variantId, $source['quantity'], TransferRequestReason::MinimumReplenishment, null);

                $this->audit->record('auto_replenishment.created', $actor, $order->organization, auditable: $request, newValues: [
                    'request_number' => $request->request_number,
                    'source_warehouse_id' => (int) $source['warehouse_id'],
                    'destination_warehouse_id' => $showroomId,
                    'product_variant_id' => (int) $variantId,
                    'quantity' => $source['quantity'],
                    'sales_order_id' => $order->getKey(),
                    'reason' => TransferRequestReason::MinimumReplenishment->value,
                ]);
            }
        }
    }

    private function requestFor(User $actor, SalesOrder $order, int $sourceId, int $destinationId): TransferRequest
    {
        $request = TransferRequest::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->where('source_warehouse_id', $sourceId)
            ->first();

        if ($request) {
            if ($request->status === TransferRequestStatus::Cancelled) {
                $request->status = TransferRequestStatus::Requested;
                $request->destination_warehouse_id = $destinationId;
                $request->requested_by_user_id = $actor->getKey();
                $request->requested_at = now();
                $request->prepared_by_user_id = null;
                $request->prepared_at = null;
                $request->cancelled_by_user_id = null;
                $request->cancelled_at = null;
                $request->cancellation_reason = null;
                $request->save();
            }

            return $request;
        }

        $request = new TransferRequest;
        $request->organization_id = $order->organization_id;
        $request->request_number = $this->numbers->next($order->organization);
        $request->source_warehouse_id = $sourceId;
        $request->destination_warehouse_id = $destinationId;
        $request->status = TransferRequestStatus::Requested;
        $request->sales_order_id = $order->getKey();
        $request->requested_by_user_id = $actor->getKey();
        $request->requested_at = now();
        $request->save();

        return $request;
    }

    private function upsertLine(TransferRequest $request, int $variantId, string $quantity, TransferRequestReason $reason, ?int $salesOrderLineId): void
    {
        $line = TransferRequestLine::query()
            ->where('organization_id', $request->organization_id)
            ->where('transfer_request_id', $request->getKey())
            ->where('product_variant_id', $variantId)
            ->where('reason', $reason->value)
            ->first();

        if ($line) {
            // A retried confirmation must not accumulate; the authoritative
            // quantity is the one just recomputed.
            $line->quantity = InventoryQuantity::normalize($quantity);
            $line->sales_order_line_id = $salesOrderLineId ?? $line->sales_order_line_id;
            $line->save();

            return;
        }

        $line = new TransferRequestLine;
        $line->organization_id = $request->organization_id;
        $line->transfer_request_id = $request->getKey();
        $line->product_variant_id = $variantId;
        $line->quantity = InventoryQuantity::normalize($quantity);
        $line->reason = $reason;
        $line->sales_order_line_id = $salesOrderLineId;
        $line->save();
    }
}
