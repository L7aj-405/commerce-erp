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

        // Same organization-wide planning lock as the manual sweep: a sweep and a
        // POS confirmation never compute the same deficit from one snapshot.
        $this->planner->lockPlanning((int) $order->organization_id);

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

        // --- 0. Customer priority at the source ---------------------------------
        // The order's reservations at a remote source were taken from
        // `available` regardless of what open replenishment promised out of it.
        // Replenishment is optional: where the promises no longer fit, the
        // not-yet-prepared MINIMUM_REPLENISHMENT lines yield (newest first).
        foreach ($remote as $sourceId => $variants) {
            $this->yieldReplenishmentToOrder($actor, $order, (int) $sourceId, array_keys($variants));
        }

        // --- A. ORDER_FULFILLMENT (pickup only) --------------------------------
        $orderRequests = [];
        if ($isPickup) {
            foreach ($remote as $sourceId => $variants) {
                $request = $this->requestFor($actor, $order, (int) $sourceId, $showroomId);
                $orderRequests[(int) $sourceId] = $request;

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
        // First pull the relevant open general replenishment onto the customer
        // request of the same route, so ONE request carries the whole picture
        // ("2 for the customer + 2 to restore the minimum") instead of two
        // requests whose sum the warehouse has to reconstruct. A move keeps the
        // total incoming unchanged, so the deficit below stays exact.
        ksort($orderRequests);
        $this->reallocateGeneralReplenishment($actor, $order, $showroomId, $orderRequests, array_values($orderVariantIds));

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

    /**
     * Customer priority: shrink not-yet-prepared MINIMUM_REPLENISHMENT lines
     * leaving `$sourceId` until the source's available stock (after this
     * order's reservation) covers every remaining free promise again.
     * Preparing / shipped requests and MANUAL lines are never touched; any
     * shortfall left on them is surfaced on the request screens.
     *
     * @param  list<int>  $variantIds
     */
    private function yieldReplenishmentToOrder(User $actor, SalesOrder $order, int $sourceId, array $variantIds): void
    {
        $organizationId = (int) $order->organization_id;

        foreach ($this->planner->promiseShortfalls($organizationId, $sourceId, $variantIds) as $variantId => $shortfall) {
            $short = $shortfall['short'];

            $lines = TransferRequestLine::query()
                ->join('transfer_requests as tr', 'tr.id', '=', 'transfer_request_lines.transfer_request_id')
                ->where('transfer_request_lines.organization_id', $organizationId)
                ->where('tr.organization_id', $organizationId)
                ->where('tr.source_warehouse_id', $sourceId)
                ->where('tr.status', TransferRequestStatus::Requested->value)
                ->where('transfer_request_lines.product_variant_id', $variantId)
                ->where('transfer_request_lines.reason', TransferRequestReason::MinimumReplenishment->value)
                ->orderByDesc('transfer_request_lines.id')
                ->select('transfer_request_lines.*')
                ->sharedLock() // latest committed rows, whatever the caller's snapshot
                ->get();

            foreach ($lines as $line) {
                if (InventoryQuantity::compare($short, InventoryQuantity::ZERO) <= 0) {
                    break;
                }
                $take = InventoryQuantity::compare($line->quantity, $short) < 0 ? InventoryQuantity::normalize($line->quantity) : $short;
                $this->releaseFromLine($actor, $order, $line, $take, 'transfer_request.replenishment_yielded_to_order', null);
                $short = InventoryQuantity::subtract($short, $take);
            }
        }
    }

    /**
     * Move open general (not order-linked, still `requested`) replenishment for
     * the order's variants onto the order's own request of the same route —
     * only up to what the Showroom still needs once this customer is served
     * (minimum − available − incoming that stays where it is). Unrelated
     * products of a general request are never touched; a general request left
     * with nothing is cancelled with an explicit reason (kept in history).
     *
     * @param  array<int, TransferRequest>  $orderRequests  source id => this order's request
     * @param  list<int>  $variantIds
     */
    private function reallocateGeneralReplenishment(User $actor, SalesOrder $order, int $showroomId, array $orderRequests, array $variantIds): void
    {
        $organizationId = (int) $order->organization_id;
        if ($orderRequests === [] || $variantIds === [] || ! $this->planner->isEnabled($organizationId, $showroomId)) {
            return;
        }

        sort($variantIds);
        foreach ($variantIds as $variantId) {
            $minimum = $this->planner->minimumFor($organizationId, $showroomId, $variantId);
            if (InventoryQuantity::compare($minimum, InventoryQuantity::ZERO) <= 0) {
                continue;
            }

            $candidates = TransferRequestLine::query()
                ->join('transfer_requests as tr', 'tr.id', '=', 'transfer_request_lines.transfer_request_id')
                ->where('transfer_request_lines.organization_id', $organizationId)
                ->where('tr.organization_id', $organizationId)
                ->whereNull('tr.sales_order_id')
                ->where('tr.destination_warehouse_id', $showroomId)
                ->whereIn('tr.source_warehouse_id', array_keys($orderRequests))
                ->where('tr.status', TransferRequestStatus::Requested->value)
                ->where('transfer_request_lines.product_variant_id', $variantId)
                ->where('transfer_request_lines.reason', TransferRequestReason::MinimumReplenishment->value)
                ->orderBy('tr.source_warehouse_id')
                ->orderBy('transfer_request_lines.id')
                ->select('transfer_request_lines.*', 'tr.source_warehouse_id as route_source_id')
                // Locking read: a general request committed by a concurrent
                // sweep just before we got the planning lock must be seen here,
                // or it would be left in place AND topped up again (5, not 4).
                ->sharedLock()
                ->get();
            if ($candidates->isEmpty()) {
                continue;
            }

            $candidateTotal = $candidates->reduce(fn (string $c, $l) => InventoryQuantity::add($c, $l->quantity), InventoryQuantity::ZERO);
            $staying = InventoryQuantity::subtract($this->planner->incomingReplenishmentQty($organizationId, $showroomId, $variantId), $candidateTotal);
            $need = InventoryQuantity::subtract($minimum, InventoryQuantity::add($this->planner->availableAt($organizationId, $showroomId, $variantId), $staying));

            foreach ($candidates as $line) {
                if (InventoryQuantity::compare($need, InventoryQuantity::ZERO) <= 0) {
                    break;
                }
                $take = InventoryQuantity::compare($line->quantity, $need) < 0 ? InventoryQuantity::normalize($line->quantity) : $need;
                $target = $orderRequests[(int) $line->route_source_id];
                [$moved, $trail] = $this->releaseFromLine($actor, $order, $line, $take, 'transfer_request.replenishment_reallocated_to_order', $target);
                if (InventoryQuantity::compare($moved, InventoryQuantity::ZERO) > 0) {
                    $before = $this->replenishmentQuantity($target, $variantId);
                    $this->upsertLine($target, $variantId, $moved, TransferRequestReason::MinimumReplenishment, null, reallocated: true);
                    // Receiving side of the trail: where it came from, how much,
                    // and this request's resulting replenishment quantity.
                    $this->audit->record('transfer_request.replenishment_received_from_general', $actor, $order->organization, auditable: $target,
                        oldValues: ['quantity' => $before],
                        newValues: ['quantity' => $this->replenishmentQuantity($target, $variantId)] + $trail);
                    $need = InventoryQuantity::subtract($need, $moved);
                }
            }
        }
    }

    /**
     * Take `$quantity` off a requested replenishment line (re-read under lock)
     * and return what was actually taken. The line is reduced, removed (other
     * products remain on its request) or — when it was the request's only
     * demand — its request is cancelled with an explicit reason, keeping the
     * line for history. Audited on the source request with old/new quantities
     * and the customer order, and on the receiving request when there is one.
     */
    /** Current MINIMUM_REPLENISHMENT quantity of a variant on a request. */
    private function replenishmentQuantity(TransferRequest $request, int $variantId): string
    {
        $quantity = TransferRequestLine::query()
            ->where('organization_id', $request->organization_id)
            ->where('transfer_request_id', $request->getKey())
            ->where('product_variant_id', $variantId)
            ->where('reason', TransferRequestReason::MinimumReplenishment->value)
            ->value('quantity');

        return $quantity !== null ? InventoryQuantity::normalize((string) $quantity) : InventoryQuantity::ZERO;
    }

    /** @return array{0: string, 1: array<string, mixed>} [quantity taken, audit trail values] */
    private function releaseFromLine(User $actor, SalesOrder $order, TransferRequestLine $line, string $quantity, string $event, ?TransferRequest $target): array
    {
        $request = TransferRequest::query()
            ->where('organization_id', $line->organization_id)
            ->whereKey($line->transfer_request_id)
            ->lockForUpdate()
            ->firstOrFail();
        $line = TransferRequestLine::query()->whereKey($line->getKey())->where('organization_id', $line->organization_id)->lockForUpdate()->first();
        if (! $line || $request->status !== TransferRequestStatus::Requested) {
            return [InventoryQuantity::ZERO, []];
        }

        $old = InventoryQuantity::normalize($line->quantity);
        $take = InventoryQuantity::compare($old, $quantity) < 0 ? $old : InventoryQuantity::normalize($quantity);
        $new = InventoryQuantity::subtract($old, $take);
        $otherLines = $request->lines()->whereKeyNot($line->getKey())->exists();
        $outcome = 'reduced';

        if (InventoryQuantity::compare($new, InventoryQuantity::ZERO) > 0) {
            $line->quantity = $new;
            $line->save();
        } elseif ($otherLines) {
            $line->delete();
            $outcome = 'line_removed';
        } else {
            // Its only demand moved / yielded: cancel it with an explicit reason
            // (no longer counted as incoming) and keep the line as history.
            $outcome = 'request_cancelled';
            $request->status = TransferRequestStatus::Cancelled;
            $request->cancelled_by_user_id = $actor->getKey();
            $request->cancelled_at = now();
            $request->cancellation_reason = $target
                ? "Réassort réaffecté à la demande {$target->request_number} (commande client {$order->order_number})."
                : "Stock source réservé en priorité pour la commande client {$order->order_number}.";
        }
        $request->lock_version = (int) $request->lock_version + 1;
        $request->save();

        $values = [
            'request_number' => $request->request_number,
            'product_variant_id' => (int) $line->product_variant_id,
            'reason' => TransferRequestReason::MinimumReplenishment->value,
            'sales_order_id' => $order->getKey(),
            'sales_order_number' => $order->order_number,
            'moved_quantity' => $take,
            'outcome' => $outcome,
            'target_request_id' => $target?->getKey(),
            'target_request_number' => $target?->request_number,
        ];
        $this->audit->record($event, $actor, $order->organization, auditable: $request, oldValues: ['quantity' => $old], newValues: ['quantity' => $new] + $values);

        return [$take, $values + ['from_request_id' => $request->getKey()]];
    }

    private function requestFor(User $actor, SalesOrder $order, int $sourceId, int $destinationId): TransferRequest
    {
        $request = TransferRequest::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->where('source_warehouse_id', $sourceId)
            ->lockForUpdate()
            ->first();

        if ($request) {
            if ($request->status === TransferRequestStatus::Cancelled) {
                // Reopening the (order, source) slot for a re-confirmed order.
                // The lines of the cancelled lifecycle are stale: they were not
                // counted as incoming when this run's deficits were computed, so
                // keeping them would silently inflate the request. Drop them and
                // let this run upsert the authoritative quantities. The earlier
                // cancellation remains in the audit log.
                $staleLines = $request->lines()->get(['product_variant_id', 'quantity', 'reason']);
                $request->lines()->delete();
                $this->audit->record('transfer_request.reopened_by_order', $actor, $order->organization, auditable: $request, oldValues: [
                    'cancelled_at' => $request->cancelled_at?->toIso8601String(),
                    'cancellation_reason' => $request->cancellation_reason,
                    'lines' => $staleLines->map(fn ($l) => [
                        'product_variant_id' => (int) $l->product_variant_id,
                        'quantity' => $l->quantity,
                        'reason' => $l->reason->value,
                    ])->all(),
                ], newValues: ['request_number' => $request->request_number, 'sales_order_id' => $order->getKey()]);

                $request->status = TransferRequestStatus::Requested;
                $request->destination_warehouse_id = $destinationId;
                $request->requested_by_user_id = $actor->getKey();
                $request->requested_at = now();
                $request->prepared_by_user_id = null;
                $request->prepared_at = null;
                $request->cancelled_by_user_id = null;
                $request->cancelled_at = null;
                $request->cancellation_reason = null;
                $request->lock_version = (int) $request->lock_version + 1;
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

    private function upsertLine(TransferRequest $request, int $variantId, string $quantity, TransferRequestReason $reason, ?int $salesOrderLineId, bool $reallocated = false): void
    {
        $line = TransferRequestLine::query()
            ->where('organization_id', $request->organization_id)
            ->where('transfer_request_id', $request->getKey())
            ->where('product_variant_id', $variantId)
            ->where('reason', $reason->value)
            ->lockForUpdate()
            ->first();

        if ($line) {
            // ORDER_FULFILLMENT: the authoritative quantity is the one just
            // recomputed from the allocations — a retry must not accumulate.
            // MINIMUM_REPLENISHMENT: the deficit was computed WITH this line
            // already counted as incoming, so it is the missing top-up and is
            // added (overwriting would silently drop the earlier top-up).
            $line->quantity = $reason === TransferRequestReason::MinimumReplenishment
                ? InventoryQuantity::add($line->quantity, $quantity)
                : InventoryQuantity::normalize($quantity);
            $line->sales_order_line_id = $salesOrderLineId ?? $line->sales_order_line_id;
            if ($reallocated) {
                $line->reallocated_quantity = InventoryQuantity::add($line->reallocated_quantity ?? InventoryQuantity::ZERO, $quantity);
            }
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
        $line->reallocated_quantity = $reallocated ? InventoryQuantity::normalize($quantity) : null;
        $line->save();
    }
}
