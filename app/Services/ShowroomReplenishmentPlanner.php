<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\TransferRequestLine;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentOverride;
use App\Models\WarehouseReplenishmentSetting;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Minimum-stock replenishment maths for an operational warehouse (the POS
 * Showroom). Pure calculation — it never writes. It answers:
 *
 *   "How much MORE of this variant should physically arrive so the Showroom
 *    keeps its minimum display stock, given what is already there and already
 *    on its way?"
 *
 *   deficit = minimum − ( available_now + active_incoming_replenishment )
 *
 * `available` is `on_hand − reserved` (a confirmed customer Order's Showroom
 * allocation is already reserved, so it lowers this). ORDER_FULFILLMENT
 * transfers are earmarked for a customer and do NOT count as free incoming
 * stock — only MINIMUM_REPLENISHMENT / MANUAL incoming does. Replenishment is
 * best-effort and always yields priority to customer demand (§18).
 *
 * Source side: a source can only give what is `transferable` = available
 * (on_hand − reserved) − quantity already promised by other ACTIVE outgoing
 * MINIMUM_REPLENISHMENT / MANUAL lines. Those promises are not reservations
 * (the units stay in the source's on_hand until receipt), so without this
 * netting two requests could promise the same physical units. ORDER_FULFILLMENT
 * lines are NOT netted: their quantity is already a reservation at the source
 * and therefore already excluded from `available`.
 *
 * Writers that act on these numbers (sweep, order confirmation, manual
 * creation, edit, restore) must hold {@see lockPlanning()} for the
 * organization so two of them can never decide from the same snapshot.
 */
class ShowroomReplenishmentPlanner
{
    public function __construct(private readonly TransferRequestNumberGenerator $numbers) {}

    /** Statuses whose lines still represent stock on its way (not yet in the destination's on_hand). */
    public const ACTIVE_STATUSES = ['requested', 'preparing', 'shipped'];

    /** Line reasons that represent free (non-customer) stock movement. */
    public const FREE_REASONS = ['minimum_replenishment', 'manual'];

    /**
     * Serialise every transfer-request planning decision of one organization.
     * Reuses the organization's `transfer_request_sequences` row (the row the
     * number generator locks anyway) so there is exactly one lock to take and
     * no lock-ordering deadlock between planners. Must run inside a
     * transaction; the lock is held until it commits.
     */
    public function lockPlanning(int $organizationId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Transfer request planning must run inside a database transaction.');
        }

        // One exclusive lock, also on an organization's first planning ever
        // (see TransferRequestNumberGenerator::lockSequence for the deadlocks
        // the previous SELECT FOR UPDATE + INSERT IGNORE shape produced).
        $this->numbers->lockSequence($organizationId);
    }

    /**
     * Free-stock quantities already promised OUT of `$sourceWarehouseId` by
     * active requests (minimum replenishment / manual lines only).
     *
     * @param  list<int>  $variantIds
     * @return array<int, string>  variant id => committed quantity
     */
    public function committedOutgoing(int $organizationId, int $sourceWarehouseId, array $variantIds, ?int $excludeRequestId = null): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $out = [];
        TransferRequestLine::query()
            ->join('transfer_requests as tr', 'tr.id', '=', 'transfer_request_lines.transfer_request_id')
            ->where('transfer_request_lines.organization_id', $organizationId)
            ->where('tr.organization_id', $organizationId)
            ->where('tr.source_warehouse_id', $sourceWarehouseId)
            ->whereIn('tr.status', self::ACTIVE_STATUSES)
            ->when($excludeRequestId !== null, fn ($query) => $query->where('tr.id', '!=', $excludeRequestId))
            ->whereIn('transfer_request_lines.product_variant_id', $variantIds)
            ->whereIn('transfer_request_lines.reason', self::FREE_REASONS)
            // Locking read: sees the latest committed requests even when the
            // caller's transaction snapshot predates the planning lock (POS
            // checkout reads before confirming; MySQL REPEATABLE READ).
            ->sharedLock()
            ->get(['transfer_request_lines.product_variant_id', 'transfer_request_lines.quantity'])
            ->each(function ($row) use (&$out) {
                $variantId = (int) $row->product_variant_id;
                $out[$variantId] = InventoryQuantity::add($out[$variantId] ?? InventoryQuantity::ZERO, $row->quantity);
            });

        return $out;
    }

    /**
     * What a source can still promise for one variant: available − active free
     * outgoing commitments, floored at zero.
     */
    public function transferableAt(int $organizationId, int $warehouseId, int $variantId, ?int $excludeRequestId = null): string
    {
        return $this->transferableMap($organizationId, $warehouseId, [$variantId], $excludeRequestId)[$variantId]['transferable'];
    }

    /**
     * Batched stock picture of a source for the request editors and planners.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{on_hand: string, reserved: string, available: string, committed: string, transferable: string}>
     */
    public function transferableMap(int $organizationId, int $warehouseId, array $variantIds, ?int $excludeRequestId = null): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $balances = InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->get(['product_variant_id', 'on_hand', 'reserved'])
            ->keyBy(fn ($b) => (int) $b->product_variant_id);
        $committed = $this->committedOutgoing($organizationId, $warehouseId, $variantIds, $excludeRequestId);

        $out = [];
        foreach ($variantIds as $variantId) {
            $balance = $balances->get($variantId);
            $onHand = $balance ? InventoryQuantity::normalize((string) $balance->on_hand) : InventoryQuantity::ZERO;
            $reserved = $balance ? InventoryQuantity::normalize((string) $balance->reserved) : InventoryQuantity::ZERO;
            $available = InventoryQuantity::subtract($onHand, $reserved);
            $promised = $committed[$variantId] ?? InventoryQuantity::ZERO;
            $transferable = InventoryQuantity::subtract($available, $promised);

            $out[$variantId] = [
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'committed' => $promised,
                'transferable' => InventoryQuantity::compare($transferable, InventoryQuantity::ZERO) > 0 ? $transferable : InventoryQuantity::ZERO,
            ];
        }

        return $out;
    }

    /**
     * Variants whose current available stock at `$warehouseId` no longer covers
     * what active requests promise out of it (available < committed).
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{available: string, committed: string, short: string, requests: list<string>}>
     */
    public function promiseShortfalls(int $organizationId, int $warehouseId, array $variantIds): array
    {
        $out = [];
        foreach ($this->transferableMap($organizationId, $warehouseId, $variantIds) as $variantId => $row) {
            $short = InventoryQuantity::subtract($row['committed'], $row['available']);
            if (InventoryQuantity::compare($short, InventoryQuantity::ZERO) <= 0) {
                continue;
            }
            $out[$variantId] = [
                'available' => $row['available'],
                'committed' => $row['committed'],
                'short' => $short,
                'requests' => TransferRequestLine::query()
                    ->join('transfer_requests as tr', 'tr.id', '=', 'transfer_request_lines.transfer_request_id')
                    ->where('transfer_request_lines.organization_id', $organizationId)
                    ->where('tr.organization_id', $organizationId)
                    ->where('tr.source_warehouse_id', $warehouseId)
                    ->whereIn('tr.status', self::ACTIVE_STATUSES)
                    ->where('transfer_request_lines.product_variant_id', $variantId)
                    ->whereIn('transfer_request_lines.reason', self::FREE_REASONS)
                    // tr.id must be selected: MySQL rejects DISTINCT + ORDER BY on an unselected column.
                    ->distinct()->orderBy('tr.id')->limit(5)
                    ->get(['tr.id', 'tr.request_number'])
                    ->pluck('request_number')->all(),
            ];
        }

        return $out;
    }

    /** Automatic replenishment master switch for a warehouse. */
    public function isEnabled(int $organizationId, int $warehouseId): bool
    {
        return WarehouseReplenishmentSetting::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('auto_replenish', true)
            ->exists();
    }

    /** Precedence: per-variant override → warehouse default → 0 (no minimum). */
    public function minimumFor(int $organizationId, int $warehouseId, int $variantId): string
    {
        $override = WarehouseReplenishmentOverride::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->value('minimum_quantity');

        if ($override !== null) {
            return InventoryQuantity::normalize((string) $override);
        }

        $default = WarehouseReplenishmentSetting::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('auto_replenish', true)
            ->value('default_minimum_quantity');

        return $default !== null ? InventoryQuantity::normalize((string) $default) : InventoryQuantity::ZERO;
    }

    /** `on_hand − reserved` at a warehouse for a variant, floored at zero. */
    public function availableAt(int $organizationId, int $warehouseId, int $variantId): string
    {
        $balance = InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->first(['on_hand', 'reserved']);

        if (! $balance) {
            return InventoryQuantity::ZERO;
        }

        $available = InventoryQuantity::subtract($balance->on_hand, $balance->reserved);

        return InventoryQuantity::compare($available, InventoryQuantity::ZERO) > 0 ? $available : InventoryQuantity::ZERO;
    }

    /**
     * Quantity of this variant already on its way to `$warehouseId` for
     * replenishment (active requests only). Order-fulfillment lines are excluded
     * — that stock belongs to a customer.
     */
    public function incomingReplenishmentQty(int $organizationId, int $warehouseId, int $variantId, ?int $excludeRequestId = null): string
    {
        return $this->incomingFreeMap($organizationId, $warehouseId, [$variantId], $excludeRequestId)[$variantId] ?? InventoryQuantity::ZERO;
    }

    /**
     * Still-unmet minimum top-up for one variant at the operational warehouse.
     * Returns '0.0000' when the minimum is met, no minimum is configured, or
     * automatic replenishment is disabled.
     */
    public function deficit(int $organizationId, int $warehouseId, int $variantId, ?int $excludeRequestId = null): string
    {
        if (! $this->isEnabled($organizationId, $warehouseId)) {
            return InventoryQuantity::ZERO;
        }

        $minimum = $this->minimumFor($organizationId, $warehouseId, $variantId);
        if (InventoryQuantity::compare($minimum, InventoryQuantity::ZERO) <= 0) {
            return InventoryQuantity::ZERO;
        }

        $projected = InventoryQuantity::add(
            $this->availableAt($organizationId, $warehouseId, $variantId),
            $this->incomingReplenishmentQty($organizationId, $warehouseId, $variantId, $excludeRequestId),
        );

        $deficit = InventoryQuantity::subtract($minimum, $projected);

        return InventoryQuantity::compare($deficit, InventoryQuantity::ZERO) > 0 ? $deficit : InventoryQuantity::ZERO;
    }

    /**
     * The replenishment candidate ProductVariants for one operational warehouse,
     * without ever requiring a pre-existing Showroom balance row (§5):
     *
     *   - every explicit (warehouse, variant) minimum override, PLUS
     *   - when the warehouse has an active positive default minimum: every
     *     active same-organisation ProductVariant that currently holds stock
     *     ( on_hand > 0 ) in ANY active warehouse of the organisation — i.e. it
     *     has transferable company stock and is worth topping up.
     *
     * Two bounded queries, no per-variant scan, no cross-tenant catalogue read.
     *
     * @return list<int>
     */
    public function candidateVariantIds(int $organizationId, int $warehouseId): array
    {
        $overrides = WarehouseReplenishmentOverride::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->pluck('product_variant_id');

        $default = WarehouseReplenishmentSetting::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('auto_replenish', true)
            ->value('default_minimum_quantity');

        $stocked = collect();
        if ($default !== null && InventoryQuantity::compare(InventoryQuantity::normalize((string) $default), InventoryQuantity::ZERO) > 0) {
            $stocked = ProductVariant::query()
                ->where('product_variants.organization_id', $organizationId)
                ->where('product_variants.status', CatalogStatus::Active->value)
                ->whereExists(fn ($query) => $query->selectRaw('1')
                    ->from('inventory_balances as ib')
                    ->join('warehouses as w', 'w.id', '=', 'ib.warehouse_id')
                    ->whereColumn('ib.product_variant_id', 'product_variants.id')
                    ->where('ib.organization_id', $organizationId)
                    ->where('ib.on_hand', '>', 0)
                    ->where('w.status', WarehouseStatus::Active->value))
                ->pluck('product_variants.id');
        }

        return $overrides->merge($stocked)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Batched {@see deficit()} for a set of variants — one query per input
     * (minimums, Showroom availability, active incoming replenishment) instead
     * of three per variant. Only variants with a strictly positive deficit are
     * returned.
     *
     * @param  list<int>  $variantIds
     * @return array<int, string>  variant id => deficit
     */
    public function deficits(int $organizationId, int $warehouseId, array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === [] || ! $this->isEnabled($organizationId, $warehouseId)) {
            return [];
        }

        $minimums = $this->minimumsMap($organizationId, $warehouseId, $variantIds);

        $available = InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->get(['product_variant_id', 'on_hand', 'reserved'])
            ->mapWithKeys(fn ($b) => [(int) $b->product_variant_id => InventoryQuantity::subtract($b->on_hand, $b->reserved)]);

        $incoming = collect($this->incomingFreeMap($organizationId, $warehouseId, $variantIds));

        $out = [];
        foreach ($variantIds as $variantId) {
            $minimum = $minimums[$variantId];
            if (InventoryQuantity::compare($minimum, InventoryQuantity::ZERO) <= 0) {
                continue;
            }

            $avail = $available->get($variantId, InventoryQuantity::ZERO);
            $avail = InventoryQuantity::compare($avail, InventoryQuantity::ZERO) > 0 ? $avail : InventoryQuantity::ZERO;
            $projected = InventoryQuantity::add($avail, $incoming->get($variantId, InventoryQuantity::ZERO));
            $deficit = InventoryQuantity::subtract($minimum, $projected);

            if (InventoryQuantity::compare($deficit, InventoryQuantity::ZERO) > 0) {
                $out[$variantId] = $deficit;
            }
        }

        return $out;
    }

    /**
     * Batched {@see minimumFor()}: override → warehouse default (when automatic
     * replenishment is on) → 0.
     *
     * @param  list<int>  $variantIds
     * @return array<int, string>
     */
    public function minimumsMap(int $organizationId, int $warehouseId, array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $overrides = WarehouseReplenishmentOverride::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('minimum_quantity', 'product_variant_id');

        $default = WarehouseReplenishmentSetting::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('auto_replenish', true)
            ->value('default_minimum_quantity');
        $default = $default !== null ? InventoryQuantity::normalize((string) $default) : InventoryQuantity::ZERO;

        $out = [];
        foreach ($variantIds as $variantId) {
            $out[$variantId] = isset($overrides[$variantId]) ? InventoryQuantity::normalize((string) $overrides[$variantId]) : $default;
        }

        return $out;
    }

    /**
     * Batched {@see incomingReplenishmentQty()}: free (replenishment / manual)
     * quantity on its way to `$warehouseId` on active requests.
     *
     * @param  list<int>  $variantIds
     * @return array<int, string>  variant id => quantity (only variants with incoming stock)
     */
    public function incomingFreeMap(int $organizationId, int $warehouseId, array $variantIds, ?int $excludeRequestId = null): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $out = [];
        TransferRequestLine::query()
            ->join('transfer_requests as tr', 'tr.id', '=', 'transfer_request_lines.transfer_request_id')
            ->where('transfer_request_lines.organization_id', $organizationId)
            ->where('tr.organization_id', $organizationId)
            ->where('tr.destination_warehouse_id', $warehouseId)
            ->whereIn('tr.status', self::ACTIVE_STATUSES)
            ->when($excludeRequestId !== null, fn ($query) => $query->where('tr.id', '!=', $excludeRequestId))
            ->whereIn('transfer_request_lines.product_variant_id', $variantIds)
            ->whereIn('transfer_request_lines.reason', self::FREE_REASONS)
            // Locking read: sees the latest committed requests even when the
            // caller's transaction snapshot predates the planning lock (POS
            // checkout reads before confirming; MySQL REPEATABLE READ).
            ->sharedLock()
            ->get(['transfer_request_lines.product_variant_id', 'transfer_request_lines.quantity'])
            ->each(function ($row) use (&$out) {
                $variantId = (int) $row->product_variant_id;
                $out[$variantId] = InventoryQuantity::add($out[$variantId] ?? InventoryQuantity::ZERO, $row->quantity);
            });

        return $out;
    }

    /**
     * Deterministic same-organization sources able to cover `$quantity` of a
     * variant, preferred source first then ascending warehouse id, each capped
     * at its {@see transferableAt()} minus `$alreadyClaimed[warehouseId]`.
     * Best-effort — the returned lines may sum to less than `$quantity`; the
     * caller decides whether a shortfall blocks (customer Order) or is simply
     * dropped (minimum replenishment).
     *
     * @param  array<int, string>  $alreadyClaimed  warehouse_id => qty already earmarked this run
     * @return list<array{warehouse_id: int, quantity: string}>
     */
    public function sourcesFor(
        int $organizationId,
        int $destinationWarehouseId,
        int $variantId,
        string $quantity,
        ?int $preferredSourceId = null,
        array $alreadyClaimed = [],
    ): array {
        $remaining = InventoryQuantity::normalize($quantity);
        if (InventoryQuantity::compare($remaining, InventoryQuantity::ZERO) <= 0) {
            return [];
        }

        $warehouseIds = Warehouse::query()
            ->where('organization_id', $organizationId)
            ->where('status', WarehouseStatus::Active->value)
            ->where('id', '!=', $destinationWarehouseId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($preferredSourceId !== null && in_array($preferredSourceId, $warehouseIds, true)) {
            $warehouseIds = array_merge([$preferredSourceId], array_values(array_diff($warehouseIds, [$preferredSourceId])));
        }

        $lines = [];
        foreach ($warehouseIds as $warehouseId) {
            // Transferable, not merely available: units already promised to
            // another active request must not be promised a second time.
            $available = InventoryQuantity::subtract(
                $this->transferableAt($organizationId, $warehouseId, $variantId),
                $alreadyClaimed[$warehouseId] ?? InventoryQuantity::ZERO,
            );
            if (InventoryQuantity::compare($available, InventoryQuantity::ZERO) <= 0) {
                continue;
            }

            $take = InventoryQuantity::compare($available, $remaining) < 0 ? $available : $remaining;
            $lines[] = ['warehouse_id' => $warehouseId, 'quantity' => $take];
            $remaining = InventoryQuantity::subtract($remaining, $take);

            if (InventoryQuantity::compare($remaining, InventoryQuantity::ZERO) === 0) {
                break;
            }
        }

        return $lines;
    }
}
