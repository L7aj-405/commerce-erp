<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\TransferRequestLine;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentOverride;
use App\Models\WarehouseReplenishmentSetting;
use App\Support\InventoryQuantity;

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
 */
class ShowroomReplenishmentPlanner
{
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
        return TransferRequestLine::query()
            ->where('transfer_request_lines.organization_id', $organizationId)
            ->where('product_variant_id', $variantId)
            ->whereIn('reason', [TransferRequestReason::MinimumReplenishment->value, TransferRequestReason::Manual->value])
            ->whereHas('transferRequest', function ($query) use ($warehouseId, $excludeRequestId) {
                $query->where('destination_warehouse_id', $warehouseId)
                    ->whereIn('status', [
                        TransferRequestStatus::Requested->value,
                        TransferRequestStatus::Preparing->value,
                        TransferRequestStatus::Shipped->value,
                    ]);
                if ($excludeRequestId !== null) {
                    $query->where('id', '!=', $excludeRequestId);
                }
            })
            ->get(['quantity'])
            ->reduce(fn (string $carry, TransferRequestLine $line) => InventoryQuantity::add($carry, $line->quantity), InventoryQuantity::ZERO);
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

        $available = InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->get(['product_variant_id', 'on_hand', 'reserved'])
            ->mapWithKeys(fn ($b) => [(int) $b->product_variant_id => InventoryQuantity::subtract($b->on_hand, $b->reserved)]);

        $incoming = TransferRequestLine::query()
            ->where('transfer_request_lines.organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('reason', [TransferRequestReason::MinimumReplenishment->value, TransferRequestReason::Manual->value])
            ->whereHas('transferRequest', fn ($query) => $query
                ->where('destination_warehouse_id', $warehouseId)
                ->whereIn('status', [
                    TransferRequestStatus::Requested->value,
                    TransferRequestStatus::Preparing->value,
                    TransferRequestStatus::Shipped->value,
                ]))
            ->get(['product_variant_id', 'quantity'])
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $c, $r) => InventoryQuantity::add($c, $r->quantity), InventoryQuantity::ZERO));

        $out = [];
        foreach ($variantIds as $variantId) {
            $minimum = isset($overrides[$variantId])
                ? InventoryQuantity::normalize((string) $overrides[$variantId])
                : $default;
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
     * Deterministic same-organization sources able to cover `$quantity` of a
     * variant, preferred source first then ascending warehouse id, each capped
     * at its transferable `available` minus `$alreadyClaimed[warehouseId]`.
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
            $available = InventoryQuantity::subtract(
                $this->availableAt($organizationId, $warehouseId, $variantId),
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
