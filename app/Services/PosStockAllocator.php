<?php

namespace App\Services;

use App\Enums\WarehouseStatus;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\Warehouse;
use App\Support\InventoryQuantity;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PosStockAllocator
{
    /**
     * Company-wide allocation for ONE catalogue line: fill the preferred
     * warehouse first, then every other active same-organization warehouse in
     * deterministic ascending-id order, each capped at `available - reserved`
     * minus what other lines of the same Order already hold. Never crosses
     * organizations.
     *
     * STRICT: throws when the company-wide available stock cannot cover the
     * requested quantity. Used by the POS (instant sale) and by
     * ConfirmSalesOrderAction, where a shortage must block.
     *
     * @return list<array{warehouse: Warehouse, quantity: string}>
     */
    public function allocations(
        SalesOrder $order,
        Warehouse $preferredWarehouse,
        ProductVariant $variant,
        int|float|string $quantity,
        ?SalesOrderLine $ignoreLine = null,
    ): array {
        abort_unless(
            $preferredWarehouse->organization_id === $order->organization_id
            && $variant->organization_id === $order->organization_id,
            404,
        );

        $quantity = InventoryQuantity::positive($quantity);
        $plan = $this->plan($order, $variant, $quantity, $ignoreLine, $preferredWarehouse->getKey());

        if (InventoryQuantity::compare($plan['shortfall'], InventoryQuantity::ZERO) > 0) {
            $available = InventoryQuantity::subtract($quantity, $plan['shortfall']);

            throw ValidationException::withMessages([
                'quantity' => "Stock société insuffisant. {$this->humanQuantity($available)} unités disponibles au total.",
            ]);
        }

        return $plan['allocations'];
    }

    /**
     * Best-effort company-wide plan for a DRAFT catalogue line. Same fill order
     * as {@see allocations()} but NON-THROWING: any quantity the stock-bearing
     * warehouses cannot cover is parked on the preferred warehouse, so the
     * returned plan always sums to the full line quantity. A genuine
     * company-wide shortage is surfaced later, at confirmation, not while the
     * Draft is still being edited (backorder / procurement stays possible).
     *
     * @return list<array{warehouse: Warehouse, quantity: string}>
     */
    public function planProvisional(
        SalesOrder $order,
        Warehouse $preferredWarehouse,
        ProductVariant $variant,
        int|float|string $quantity,
        ?SalesOrderLine $ignoreLine = null,
    ): array {
        abort_unless(
            $preferredWarehouse->organization_id === $order->organization_id
            && $variant->organization_id === $order->organization_id,
            404,
        );

        $quantity = InventoryQuantity::positive($quantity);
        $plan = $this->plan($order, $variant, $quantity, $ignoreLine, $preferredWarehouse->getKey());
        $allocations = $plan['allocations'];

        if (InventoryQuantity::compare($plan['shortfall'], InventoryQuantity::ZERO) > 0) {
            foreach ($allocations as $index => $entry) {
                if ($entry['warehouse']->getKey() === $preferredWarehouse->getKey()) {
                    $allocations[$index]['quantity'] = InventoryQuantity::add($entry['quantity'], $plan['shortfall']);

                    return $allocations;
                }
            }
            $allocations[] = ['warehouse' => $preferredWarehouse, 'quantity' => $plan['shortfall']];
        }

        return $allocations;
    }

    /**
     * @return array{allocations: list<array{warehouse: Warehouse, quantity: string}>, shortfall: string}
     */
    private function plan(
        SalesOrder $order,
        ProductVariant $variant,
        string $quantity,
        ?SalesOrderLine $ignoreLine,
        int $preferredWarehouseId,
    ): array {
        $alreadyAllocated = $this->otherDraftAllocations($order, $variant, $ignoreLine);
        $remaining = $quantity;
        $allocations = [];

        foreach ($this->eligibleBalances($order->organization_id, $variant->getKey(), $preferredWarehouseId) as $balance) {
            $available = InventoryQuantity::subtract(
                $balance->available,
                $alreadyAllocated->get($balance->warehouse_id, InventoryQuantity::ZERO),
            );

            if (InventoryQuantity::compare($available, InventoryQuantity::ZERO) <= 0) {
                continue;
            }

            $allocated = InventoryQuantity::compare($available, $remaining) < 0 ? $available : $remaining;
            if (InventoryQuantity::compare($allocated, InventoryQuantity::ZERO) > 0) {
                $allocations[] = ['warehouse' => $balance->warehouse, 'quantity' => $allocated];
                $remaining = InventoryQuantity::subtract($remaining, $allocated);
            }

            if (InventoryQuantity::compare($remaining, InventoryQuantity::ZERO) === 0) {
                break;
            }
        }

        return ['allocations' => $allocations, 'shortfall' => $remaining];
    }

    public function availableForLine(SalesOrder $order, ProductVariant $variant, ?SalesOrderLine $ignoreLine = null): string
    {
        $alreadyAllocated = $this->otherDraftAllocations($order, $variant, $ignoreLine);

        return $this->eligibleBalances($order->organization_id, $variant->getKey())
            ->reduce(function (string $total, InventoryBalance $balance) use ($alreadyAllocated) {
                $available = InventoryQuantity::subtract(
                    $balance->available,
                    $alreadyAllocated->get($balance->warehouse_id, InventoryQuantity::ZERO),
                );

                return InventoryQuantity::compare($available, InventoryQuantity::ZERO) > 0
                    ? InventoryQuantity::add($total, $available)
                    : $total;
            }, InventoryQuantity::ZERO);
    }

    public function totalAvailable(int $organizationId, int $variantId): string
    {
        return $this->eligibleBalances($organizationId, $variantId)
            ->reduce(
                fn (string $total, InventoryBalance $balance) => InventoryQuantity::add($total, $balance->available),
                InventoryQuantity::ZERO,
            );
    }

    /**
     * Deterministic default warehouse for a catalogue line when the operator did
     * not pick one: the active same-organization warehouse currently holding the
     * most available stock for the variant, otherwise the lowest-id active
     * warehouse. Never crosses organizations. Returns null only when the
     * organisation has no active warehouse at all.
     */
    public function preferredWarehouse(SalesOrder $order, ?ProductVariant $variant): ?Warehouse
    {
        $active = Warehouse::query()
            ->where('organization_id', $order->organization_id)
            ->where('status', WarehouseStatus::Active->value)
            ->orderBy('id')
            ->get();

        if ($active->isEmpty()) {
            return null;
        }

        if ($variant) {
            $best = $this->eligibleBalances($order->organization_id, $variant->getKey())
                ->sort(fn (InventoryBalance $a, InventoryBalance $b) => InventoryQuantity::compare($b->available, $a->available)
                    ?: ($a->warehouse_id <=> $b->warehouse_id))
                ->first(fn (InventoryBalance $balance) => InventoryQuantity::compare($balance->available, InventoryQuantity::ZERO) > 0);

            if ($best) {
                return $active->firstWhere('id', $best->warehouse_id) ?? $best->warehouse;
            }
        }

        return $active->first();
    }

    /** @return Collection<int, InventoryBalance> */
    private function eligibleBalances(int $organizationId, int $variantId, ?int $preferredWarehouseId = null): Collection
    {
        return InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('product_variant_id', $variantId)
            ->whereHas('warehouse', fn ($query) => $query->where('status', WarehouseStatus::Active->value))
            ->with('warehouse')
            ->when(
                $preferredWarehouseId,
                fn ($query, int $warehouseId) => $query->orderByRaw('CASE WHEN warehouse_id = ? THEN 0 ELSE 1 END', [$warehouseId]),
            )
            ->orderBy('warehouse_id')
            ->get();
    }

    /** @return Collection<int, string> */
    private function otherDraftAllocations(SalesOrder $order, ProductVariant $variant, ?SalesOrderLine $ignoreLine): Collection
    {
        return SalesOrderInventoryAllocation::query()
            ->where('organization_id', $order->organization_id)
            ->when($ignoreLine, fn ($query) => $query->where('sales_order_line_id', '!=', $ignoreLine->getKey()))
            ->whereHas('salesOrderLine', fn ($query) => $query
                ->where('sales_order_id', $order->getKey())
                ->where('product_variant_id', $variant->getKey()))
            ->get(['warehouse_id', 'quantity'])
            ->groupBy('warehouse_id')
            ->map(fn (Collection $allocations) => $allocations->reduce(
                fn (string $total, SalesOrderInventoryAllocation $allocation) => InventoryQuantity::add($total, $allocation->quantity),
                InventoryQuantity::ZERO,
            ));
    }

    private function humanQuantity(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.');
    }
}
