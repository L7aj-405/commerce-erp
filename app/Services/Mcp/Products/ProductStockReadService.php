<?php

namespace App\Services\Mcp\Products;

use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\InventoryQuantity;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ProductStockReadService
{
    /** @return array<string, mixed> */
    public function product(Organization $organization, int $productId): array
    {
        $organizationId = (int) $organization->getKey();
        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->whereKey($productId)
            ->with(['variants' => fn ($query) => $query->where('organization_id', $organizationId)->orderBy('id')])
            ->first();

        if (! $product) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        $byVariant = $this->balances($organization, $product->variants->pluck('id')->map(fn ($id) => (int) $id)->all());
        $totals = $this->zeroTotals();
        $variants = $product->variants->map(function (ProductVariant $variant) use ($byVariant, &$totals) {
            $stock = $this->variantStock($variant, $byVariant[$variant->getKey()] ?? []);
            $totals = $this->addTotals($totals, $stock['totals']);

            return $stock;
        })->values()->all();

        return [
            'target' => ['type' => 'product', 'id' => $product->getKey(), 'name' => $product->name],
            'totals' => $totals,
            'by_warehouse' => $this->aggregateWarehouses($variants),
            'variants' => $variants,
        ];
    }

    /** @return array<string, mixed> */
    public function variant(Organization $organization, int $variantId): array
    {
        $organizationId = (int) $organization->getKey();
        $variant = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->whereKey($variantId)
            ->with(['product' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'organization_id', 'name'])])
            ->first();

        if (! $variant) {
            throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
        }

        $byVariant = $this->balances($organization, [$variantId]);
        $stock = $this->variantStock($variant, $byVariant[$variantId] ?? []);

        return [
            'target' => [
                'type' => 'variant',
                'id' => $variant->getKey(),
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'label' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
            ],
            'totals' => $stock['totals'],
            'by_warehouse' => $stock['by_warehouse'],
        ];
    }

    /**
     * Organization-wide totals for many variants in one query (same balance
     * source and arithmetic as variant()/product()).
     *
     * @param list<int> $variantIds
     * @return array<int, array{on_hand: string, reserved: string, available: string}>
     */
    public function variantTotals(Organization $organization, array $variantIds): array
    {
        $byVariant = $this->balances($organization, $variantIds);
        $totals = [];
        foreach ($variantIds as $variantId) {
            $totals[$variantId] = array_reduce($byVariant[$variantId] ?? [], fn (array $carry, array $warehouse) => $this->addTotals($carry, $warehouse), $this->zeroTotals());
        }

        return $totals;
    }

    /**
     * @param list<int> $variantIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function balances(Organization $organization, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $organizationId = (int) $organization->getKey();

        return InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)
            ->with(['warehouse' => fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->select(['id', 'organization_id', 'name', 'code', 'status'])])
            ->orderBy('warehouse_id')
            ->orderBy('product_variant_id')
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($balances) => $balances->map(fn (InventoryBalance $balance) => [
                'warehouse' => [
                    'id' => $balance->warehouse->getKey(),
                    'name' => $balance->warehouse->name,
                    'code' => $balance->warehouse->code,
                    'status' => $balance->warehouse->status instanceof \BackedEnum
                        ? $balance->warehouse->status->value
                        : (string) $balance->warehouse->status,
                ],
                'on_hand' => (string) $balance->on_hand,
                'reserved' => (string) $balance->reserved,
                'available' => $balance->available,
            ])->values()->all())
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $warehouses
     * @return array<string, mixed>
     */
    private function variantStock(ProductVariant $variant, array $warehouses): array
    {
        $totals = $this->zeroTotals();
        foreach ($warehouses as $warehouse) {
            $totals = $this->addTotals($totals, $warehouse);
        }

        return [
            'id' => $variant->getKey(),
            'label' => $variant->label,
            'sku' => $variant->sku,
            'reference' => $variant->reference,
            'barcode' => $variant->barcode,
            'totals' => $totals,
            'by_warehouse' => $warehouses,
        ];
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @return list<array<string, mixed>>
     */
    private function aggregateWarehouses(array $variants): array
    {
        $warehouses = [];
        foreach ($variants as $variant) {
            foreach ($variant['by_warehouse'] as $stock) {
                $id = (int) $stock['warehouse']['id'];
                $warehouses[$id] ??= [
                    'warehouse' => $stock['warehouse'],
                    ...$this->zeroTotals(),
                ];
                $warehouses[$id] = [
                    'warehouse' => $warehouses[$id]['warehouse'],
                    ...$this->addTotals($warehouses[$id], $stock),
                ];
            }
        }

        ksort($warehouses);

        return array_values($warehouses);
    }

    /** @return array{on_hand: string, reserved: string, available: string} */
    private function zeroTotals(): array
    {
        return [
            'on_hand' => InventoryQuantity::ZERO,
            'reserved' => InventoryQuantity::ZERO,
            'available' => InventoryQuantity::ZERO,
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array{on_hand: string, reserved: string, available: string}
     */
    private function addTotals(array $left, array $right): array
    {
        return [
            'on_hand' => InventoryQuantity::add($left['on_hand'], $right['on_hand']),
            'reserved' => InventoryQuantity::add($left['reserved'], $right['reserved']),
            'available' => InventoryQuantity::add($left['available'], $right['available']),
        ];
    }
}
