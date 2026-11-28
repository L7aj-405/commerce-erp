<?php

namespace App\Services\Inventory;

use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TransferRequestReason;
use App\Models\InventoryReservation;
use App\Models\ProcurementReceipt;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\Fulfillment\FulfillmentEngine;
use App\Services\InventoryBalanceLocker;
use App\Services\InventoryReservationManager;
use App\Support\InventoryQuantity;
use Illuminate\Validation\ValidationException;

/**
 * Moves a customer Order's earmark along with a received internal transfer.
 *
 * `plan()` runs BEFORE the physical movement and frees the Order's active hold
 * at the source; `apply()` runs after it and holds the arrived quantity at the
 * destination. Both run inside the receipt transaction, with the Order locked.
 *
 * Invariants:
 *  - only the ORDER_FULFILLMENT quantity travels for the customer, never more
 *    than the line still has to ship;
 *  - a released or consumed reservation is history: it is never reactivated or
 *    changed. A consumed allocation stands for goods already gone. A released
 *    (or unlinked) allocation of a confirmed Order with goods still to ship is
 *    a lost hold: a NEW reservation replaces it, against free stock, audited;
 *  - allocation quantity = active reservation quantity afterwards.
 */
class TransferReceiptEarmarker
{
    private const ACTIVE = 'active';

    private const CONSUMED = 'consumed';

    private const LOST = 'lost';

    public function __construct(
        private readonly InventoryReservationManager $reservations,
        private readonly InventoryBalanceLocker $balances,
        private readonly FulfillmentEngine $fulfillments,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array<string, mixed>> one entry per order line whose goods travel on this request */
    public function plan(User $actor, TransferRequest $request, ?SalesOrder $order): array
    {
        $demand = [];
        foreach ($request->lines->where('reason', TransferRequestReason::OrderFulfillment) as $line) {
            $variantId = (int) $line->product_variant_id;
            $demand[$variantId] = InventoryQuantity::add($demand[$variantId] ?? InventoryQuantity::ZERO, $line->quantity);
        }
        if ($request->sales_order_id === null || $demand === []) {
            return [];
        }
        if (! $order || $order->status !== SalesOrderStatus::Confirmed) {
            $this->fail('order', 'order_not_confirmed');
        }

        $sourceId = (int) $request->source_warehouse_id;
        $destinationId = (int) $request->destination_warehouse_id;
        $this->lockBalances($request);
        $state = collect($this->fulfillments->lineState($order))
            ->filter(fn (array $line) => isset($demand[(int) $line['line']->product_variant_id]))
            ->sortKeys();
        $allocations = SalesOrderInventoryAllocation::query()
            ->where('organization_id', $request->organization_id)
            ->whereIn('sales_order_line_id', $state->keys()->all())
            ->orderBy('id')->lockForUpdate()->get()
            ->groupBy(fn (SalesOrderInventoryAllocation $a) => (int) $a->sales_order_line_id);

        $plan = [];
        foreach ($demand as $variantId => $quantity) {
            $unplanned = $quantity;
            $uncovered = InventoryQuantity::ZERO;

            foreach ($state as $lineId => $line) {
                if ((int) $line['line']->product_variant_id !== $variantId) {
                    continue;
                }
                $held = ($allocations[$lineId] ?? collect())->map(fn (SalesOrderInventoryAllocation $a) => $this->inspect($request, $a, $variantId));
                $source = $held->first(fn (array $h) => (int) $h['allocation']->warehouse_id === $sourceId);
                $destination = $held->first(fn (array $h) => (int) $h['allocation']->warehouse_id === $destinationId);
                $elsewhere = $this->activeQuantity($held->reject(fn (array $h) => (int) $h['allocation']->warehouse_id === $sourceId));
                $third = $this->activeQuantity($held->reject(fn (array $h) => in_array((int) $h['allocation']->warehouse_id, [$sourceId, $destinationId], true)));

                $outstanding = $this->positive(InventoryQuantity::subtract($line['quantity'], $line['shipped']));
                $open = $this->positive(InventoryQuantity::subtract($outstanding, $elsewhere));
                $uncovered = InventoryQuantity::add($uncovered, $open);

                if (! $source || $source['state'] === self::CONSUMED) {
                    continue;
                }
                $take = $this->min($unplanned, $this->min((string) $source['allocation']->quantity, $open));
                if (InventoryQuantity::compare($take, InventoryQuantity::ZERO) <= 0) {
                    continue;
                }
                $unplanned = InventoryQuantity::subtract($unplanned, $take);
                $plan[] = [
                    'variant_id' => $variantId, 'take' => $take, 'source' => $source, 'destination' => $destination,
                    // What the source and destination together may hold for this line.
                    'cap' => $this->positive(InventoryQuantity::subtract($outstanding, $third)),
                ];
            }

            // Whatever the Order still needs from this shipment must be traceable to its source allocations.
            $planned = InventoryQuantity::subtract($quantity, $unplanned);
            if (InventoryQuantity::compare($planned, $this->min($quantity, $uncovered)) < 0) {
                $this->fail('allocation', 'allocation_mismatch');
            }
        }

        // Free the source hold first, so the physical movement can take the units.
        foreach ($plan as $entry) {
            $source = $entry['source'];
            if ($source['state'] !== self::ACTIVE) {
                continue;
            }
            $this->guarded(fn () => InventoryQuantity::compare($entry['take'], $source['reservation']->quantity) === 0
                ? $this->reservations->release($actor, $request->organization, $source['reservation'])
                : $this->reservations->decrease($actor, $request->organization, $source['reservation'], $entry['take']));
        }

        return $plan;
    }

    /** @param list<array<string, mixed>> $plan */
    public function apply(User $actor, TransferRequest $request, ?SalesOrder $order, array $plan): void
    {
        $variants = $request->lines->pluck('productVariant', 'product_variant_id');

        foreach ($plan as $entry) {
            $variant = $variants[$entry['variant_id']];
            $take = $entry['take'];
            $source = $entry['source'];
            $destination = $entry['destination'];
            $sourceRow = $source['allocation'];
            $remainder = InventoryQuantity::subtract((string) $sourceRow->quantity, $take);
            $hasRemainder = InventoryQuantity::compare($remainder, InventoryQuantity::ZERO) > 0;

            if ($destination && $destination['state'] === self::ACTIVE) {
                $row = $destination['allocation'];
                $this->assertAvailable($request, $request->destinationWarehouse, $variant, $take);
                $this->guarded(fn () => $this->reservations->increase($actor, $request->organization, $destination['reservation'], $take));
                $row->quantity = InventoryQuantity::add($row->quantity, $take);
                $row->save();
                if (! $hasRemainder) {
                    $this->retire($sourceRow, $row);
                }
            } else {
                $lost = $destination && $destination['state'] === self::LOST;
                $target = $lost
                    ? $this->min(InventoryQuantity::add((string) $destination['allocation']->quantity, $take), $entry['cap'])
                    : $take;
                // A fully moved source row follows the goods; otherwise the destination gets its own row.
                $row = $hasRemainder ? $this->newRow($sourceRow) : $sourceRow;
                if ($destination) {
                    // One allocation per line and warehouse: the stale row makes way, its reservation stays as history.
                    $this->retire($destination['allocation'], $hasRemainder ? null : $row);
                }
                $this->hold($actor, $request, $order, $request->destinationWarehouse, $variant, $row, $target);
                if ($destination && $hasRemainder) {
                    $this->relinkReceipts($destination['allocation'], $row);
                }
                if ($lost) {
                    $this->recordRestored($actor, $request, $order, $destination, $row);
                }
            }
            // The units that travelled had lost their hold: they now have one at the destination.
            if ($source['state'] === self::LOST) {
                $this->recordRestored($actor, $request, $order, $source, $row);
            }

            if (! $hasRemainder) {
                continue;
            }
            if ($source['state'] === self::ACTIVE) {
                $sourceRow->quantity = $remainder;
                $sourceRow->save();

                continue;
            }
            // Lost hold on what stays at the source: replace it too, within what the line still needs.
            $keep = $this->min($remainder, $this->positive(InventoryQuantity::subtract($entry['cap'], (string) $row->quantity)));
            if (InventoryQuantity::compare($keep, InventoryQuantity::ZERO) <= 0) {
                $this->retire($sourceRow, $row);

                continue;
            }
            $replacement = $this->newRow($sourceRow);
            $this->retire($sourceRow, null);
            $this->hold($actor, $request, $order, $request->sourceWarehouse, $variant, $replacement, $keep);
            $this->relinkReceipts($sourceRow, $replacement);
            $this->recordRestored($actor, $request, $order, $source, $replacement);
        }
    }

    /**
     * Every balance the receipt touches, taken up front in the (warehouse,
     * variant) order order confirmation uses. Freeing the source hold first
     * would otherwise lock the source before a lower-id destination: a deadlock.
     */
    private function lockBalances(TransferRequest $request): void
    {
        $warehouses = collect([$request->sourceWarehouse, $request->destinationWarehouse])->sortBy(fn (Warehouse $w) => $w->getKey());
        $variants = $request->lines->pluck('productVariant')->unique('id')->sortBy('id');
        foreach ($warehouses as $warehouse) {
            foreach ($variants as $variant) {
                $this->balances->lock($request->organization, $warehouse, $variant);
            }
        }
    }

    /** @return array{allocation: SalesOrderInventoryAllocation, reservation: ?InventoryReservation, state: string} */
    private function inspect(TransferRequest $request, SalesOrderInventoryAllocation $allocation, int $variantId): array
    {
        $reservation = $allocation->inventory_reservation_id === null ? null : InventoryReservation::query()
            ->where('organization_id', $request->organization_id)
            ->whereKey($allocation->inventory_reservation_id)->first();
        $state = match ($reservation?->status) {
            InventoryReservationStatus::Active => self::ACTIVE,
            InventoryReservationStatus::Consumed => self::CONSUMED,
            default => self::LOST,
        };
        if ($state === self::ACTIVE && (
            (int) $reservation->warehouse_id !== (int) $allocation->warehouse_id
            || (int) $reservation->product_variant_id !== $variantId
            || InventoryQuantity::compare($reservation->quantity, $allocation->quantity) !== 0
        )) {
            $this->fail('reservation', 'reservation_inconsistent');
        }

        return ['allocation' => $allocation, 'reservation' => $reservation, 'state' => $state];
    }

    /** An unsaved copy of an allocation's identity; hold() gives it a warehouse and quantity. */
    private function newRow(SalesOrderInventoryAllocation $like): SalesOrderInventoryAllocation
    {
        $row = new SalesOrderInventoryAllocation;
        $row->organization_id = $like->organization_id;
        $row->sales_order_line_id = $like->sales_order_line_id;

        return $row;
    }

    private function hold(User $actor, TransferRequest $request, SalesOrder $order, Warehouse $warehouse, ProductVariant $variant, SalesOrderInventoryAllocation $row, string $quantity): void
    {
        $this->assertAvailable($request, $warehouse, $variant, $quantity);
        $row->warehouse_id = $warehouse->getKey();
        $row->quantity = InventoryQuantity::normalize($quantity);
        $row->inventory_reservation_id = null;
        $row->save();
        $reservation = $this->guarded(fn () => $this->reservations->reserve(
            $actor, $request->organization, $warehouse, $variant, $quantity,
            SalesOrderInventoryAllocation::class, $row->getKey(), $order->order_number,
        ));
        $row->inventory_reservation_id = $reservation->getKey();
        $row->save();
    }

    private function assertAvailable(TransferRequest $request, Warehouse $warehouse, ProductVariant $variant, string $quantity): void
    {
        $balance = $this->balances->lock($request->organization, $warehouse, $variant);
        $available = InventoryQuantity::subtract($balance->on_hand, $balance->reserved);
        if (InventoryQuantity::compare($available, $quantity) < 0) {
            $this->fail('quantity', 'insufficient_to_hold', [
                'warehouse' => $warehouse->name, 'sku' => $variant->sku ?? '—',
                'needed' => $this->human($quantity), 'available' => $this->human($available),
            ]);
        }
    }

    private function retire(SalesOrderInventoryAllocation $allocation, ?SalesOrderInventoryAllocation $successor): void
    {
        if ($successor) {
            $this->relinkReceipts($allocation, $successor);
        }
        $allocation->delete();
    }

    /** Supplier receipts point at the line's current allocation; their original reservation stays untouched. */
    private function relinkReceipts(SalesOrderInventoryAllocation $from, SalesOrderInventoryAllocation $to): void
    {
        ProcurementReceipt::query()->where('organization_id', $from->organization_id)
            ->where('sales_order_inventory_allocation_id', $from->getKey())
            ->update(['sales_order_inventory_allocation_id' => $to->getKey()]);
    }

    /** @param array{allocation: SalesOrderInventoryAllocation, reservation: ?InventoryReservation, state: string} $previous */
    private function recordRestored(User $actor, TransferRequest $request, SalesOrder $order, array $previous, SalesOrderInventoryAllocation $row): void
    {
        $this->audit->record('transfer_request.reservation_restored', $actor, $request->organization, auditable: $request, oldValues: [
            'allocation_id' => $previous['allocation']->getKey(),
            'reservation_id' => $previous['reservation']?->getKey(),
            'reservation_status' => $previous['reservation']?->status->value,
        ], newValues: [
            'request_number' => $request->request_number,
            'sales_order_id' => $order->getKey(),
            'sales_order_number' => $order->order_number,
            'allocation_id' => $row->getKey(),
            'reservation_id' => $row->inventory_reservation_id,
            'warehouse_id' => $row->warehouse_id,
            'quantity' => $row->quantity,
        ]);
    }

    /** The reservation ledger speaks English and uses internal keys: translate its refusals. */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException) {
            $this->fail('reservation', 'reservation_inconsistent');
        }
    }

    private function activeQuantity(iterable $held): string
    {
        $sum = InventoryQuantity::ZERO;
        foreach ($held as $h) {
            if ($h['state'] === self::ACTIVE) {
                $sum = InventoryQuantity::add($sum, (string) $h['allocation']->quantity);
            }
        }

        return $sum;
    }

    private function min(string $a, string $b): string
    {
        return InventoryQuantity::compare($a, $b) <= 0 ? InventoryQuantity::normalize($a) : InventoryQuantity::normalize($b);
    }

    private function positive(string $quantity): string
    {
        return InventoryQuantity::compare($quantity, InventoryQuantity::ZERO) > 0 ? $quantity : InventoryQuantity::ZERO;
    }

    private function human(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    private function fail(string $key, string $message, array $replace = []): never
    {
        throw ValidationException::withMessages([$key => __("transfer_receipt.{$message}", $replace)]);
    }
}
