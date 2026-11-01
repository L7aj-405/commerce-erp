<?php

namespace App\Services\Pos;

use App\Enums\SalesOrderLineType;
use App\Enums\WarehouseStatus;
use App\Exceptions\Pos\PosStockConflictException;
use App\Models\InventoryBalance;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Warehouse;
use App\Services\PosStockAllocator;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Authoritative stock revalidation of a POS cart at finalization.
 *
 * Runs inside the checkout transaction, after the cart row lock. Every
 * inventory balance of the cart's articles (active warehouses of the
 * organisation) is locked FOR UPDATE first, so a concurrent checkout of the
 * same units waits for this one and then sees its reservations. The cart's own
 * stock snapshot (the entry-time allocations) is never trusted:
 *
 *  1. company coverage — line by line, in line order, the quantity not covered
 *     by a confirmed legacy special order must fit the CURRENT free stock
 *     (on hand − reserved). Every short line is reported at once and the whole
 *     checkout is rejected (PosStockConflictException) before anything is written;
 *  2. warehouse coverage — when the company still covers the cart but an
 *     entry-time allocation no longer fits its warehouse, the allocations are
 *     re-planned with the canonical company-wide allocator (POS warehouse
 *     first, then the others) so the reservation step that follows cannot fail
 *     half-way.
 */
final class PosCartStockGuard
{
    public function __construct(private readonly PosStockAllocator $allocator) {}

    public function assertCovered(SalesOrder $cart): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The POS cart stock guard runs inside the checkout transaction.');
        }

        $lines = SalesOrderLine::query()
            ->where('organization_id', $cart->organization_id)
            ->where('sales_order_id', $cart->getKey())
            ->where('line_type', SalesOrderLineType::Catalog->value)
            ->whereNotNull('product_variant_id')
            ->with(['allocations', 'procurements', 'productVariant'])
            ->orderBy('position')->orderBy('id')
            ->get();
        if ($lines->isEmpty()) {
            return;
        }

        $balances = $this->lockBalances((int) $cart->organization_id, $lines->pluck('product_variant_id')->unique()->all());

        $free = $balances->groupBy('product_variant_id')->map(fn (Collection $rows) => $rows->reduce(
            fn (string $sum, InventoryBalance $balance) => Decimal::compare($balance->available, '0') > 0 ? Decimal::add($sum, $balance->available) : $sum,
            '0.0000',
        ));

        $conflicts = [];
        $stockLines = [];
        foreach ($lines as $line) {
            $legacy = $this->legacyCoverage($line);
            $need = Decimal::subtract((string) $line->quantity, $legacy);
            if (Decimal::compare($need, '0') <= 0) {
                continue;
            }
            $variantId = (int) $line->product_variant_id;
            $remaining = $free->get($variantId, '0.0000');
            $fromStock = Decimal::compare($remaining, $need) >= 0 ? $need : $remaining;
            $free->put($variantId, Decimal::subtract($remaining, $fromStock));
            $missing = Decimal::subtract($need, $fromStock);

            if (Decimal::compare($missing, '0') > 0) {
                $conflicts[] = [
                    'line_id' => (int) $line->getKey(),
                    'product_variant_id' => $variantId,
                    'product' => trim($line->product_name.($line->variant_name ? ' — '.$line->variant_name : '')),
                    'requested' => Decimal::normalize((string) $line->quantity),
                    'available' => Decimal::add($fromStock, $legacy),
                    'missing' => $missing,
                ];
            }
            if (Decimal::compare($legacy, '0') === 0) {
                $stockLines[] = $line;
            }
        }

        if ($conflicts !== []) {
            throw new PosStockConflictException($conflicts);
        }

        if (! $this->allocationsFit($stockLines, $balances)) {
            $this->replan($cart, $stockLines);
        }
    }

    /** @return Collection<int, InventoryBalance> */
    private function lockBalances(int $organizationId, array $variantIds): Collection
    {
        $activeWarehouses = Warehouse::query()
            ->where('organization_id', $organizationId)
            ->where('status', WarehouseStatus::Active->value)
            ->pluck('id');

        // Same (warehouse, variant) order as the reservation step that follows.
        return InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('warehouse_id', $activeWarehouses)
            ->orderBy('warehouse_id')->orderBy('product_variant_id')
            ->lockForUpdate()
            ->get();
    }

    /** Confirmed (or later) legacy POS special-order quantity on the line — still counts as coverage. */
    private function legacyCoverage(SalesOrderLine $line): string
    {
        return $line->procurements
            ->filter(fn (SalesOrderProcurement $p) => $p->status->coversSalesLine())
            ->reduce(fn (string $sum, SalesOrderProcurement $p) => Decimal::add($sum, (string) $p->quantity), '0.0000');
    }

    /**
     * @param  list<SalesOrderLine>  $lines
     * @param  Collection<int, InventoryBalance>  $balances
     */
    private function allocationsFit(array $lines, Collection $balances): bool
    {
        $free = $balances->mapWithKeys(fn (InventoryBalance $b) => [$b->warehouse_id.':'.$b->product_variant_id => Decimal::normalize($b->available)]);
        foreach ($lines as $line) {
            $allocated = '0.0000';
            foreach ($line->allocations as $allocation) {
                $key = $allocation->warehouse_id.':'.$line->product_variant_id;
                $left = Decimal::subtract($free->get($key, '0.0000'), (string) $allocation->quantity);
                if (Decimal::compare($left, '0') < 0) {
                    return false;
                }
                $free->put($key, $left);
                $allocated = Decimal::add($allocated, (string) $allocation->quantity);
            }
            if (Decimal::compare($allocated, (string) $line->quantity) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param list<SalesOrderLine> $lines */
    private function replan(SalesOrder $cart, array $lines): void
    {
        $preferred = $cart->posWarehouse()->first();
        foreach ($lines as $line) {
            $line->allocations()->delete();
        }
        foreach ($lines as $line) {
            $warehouse = $preferred ?? $this->allocator->preferredWarehouse($cart, $line->productVariant);
            foreach ($this->allocator->allocations($cart, $warehouse, $line->productVariant, (string) $line->quantity, $line) as $prepared) {
                $allocation = new SalesOrderInventoryAllocation;
                $allocation->organization_id = $cart->organization_id;
                $allocation->sales_order_line_id = $line->getKey();
                $allocation->warehouse_id = $prepared['warehouse']->getKey();
                $allocation->quantity = $prepared['quantity'];
                $allocation->save();
            }
        }
    }
}
