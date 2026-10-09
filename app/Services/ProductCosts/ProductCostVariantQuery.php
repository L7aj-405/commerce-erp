<?php

namespace App\Services\ProductCosts;

use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;

class ProductCostVariantQuery
{
    /** @param array<string, mixed> $filters */
    public function build(Organization $organization, array $filters): Builder
    {
        $query = ProductVariant::query()
            ->where('product_variants.organization_id', $organization->getKey())
            ->with(['product:id,organization_id,name,brand_id,default_category_id,status', 'product.brand:id,name', 'product.defaultCategory:id,name'])
            ->select('product_variants.*')
            ->selectSub(
                InventoryBalance::query()
                    ->selectRaw('COALESCE(SUM(on_hand), 0)')
                    ->where('organization_id', $organization->getKey())
                    ->whereColumn('product_variant_id', 'product_variants.id'),
                'current_stock',
            );

        $stock = $filters['stock'] ?? 'in_stock';
        if ($stock === 'in_stock') {
            $query->whereExists(fn ($balances) => $balances->selectRaw('1')->from('inventory_balances')
                ->whereColumn('inventory_balances.product_variant_id', 'product_variants.id')
                ->where('inventory_balances.organization_id', $organization->getKey())
                ->where('inventory_balances.on_hand', '>', 0));
        } elseif ($stock === 'out_of_stock') {
            $query->whereNotExists(fn ($balances) => $balances->selectRaw('1')->from('inventory_balances')
                ->whereColumn('inventory_balances.product_variant_id', 'product_variants.id')
                ->where('inventory_balances.organization_id', $organization->getKey())
                ->where('inventory_balances.on_hand', '>', 0));
        }

        $cost = $filters['cost'] ?? 'missing';
        if ($cost === 'missing') {
            $query->whereNull('product_variants.purchase_price');
        } elseif ($cost === 'present') {
            $query->whereNotNull('product_variants.purchase_price');
        }

        $query->when($filters['brand'] ?? null, fn (Builder $query, int $brand) => $query
            ->whereHas('product', fn (Builder $product) => $product->where('brand_id', $brand)))
            ->when($filters['category'] ?? null, fn (Builder $query, int $category) => $query
                ->whereHas('product', fn (Builder $product) => $product->where('default_category_id', $category)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $escaped = addcslashes($search, '%_\\');
                $query->where(function (Builder $query) use ($escaped) {
                    $query->where('product_variants.sku', 'like', "%{$escaped}%")
                        ->orWhere('product_variants.reference', 'like', "%{$escaped}%")
                        ->orWhere('product_variants.barcode', 'like', "%{$escaped}%")
                        ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$escaped}%"));
                });
            });

        return $query->orderBy(
            $organization->products()->select('name')->whereColumn('products.id', 'product_variants.product_id')->limit(1),
        )->orderBy('product_variants.id');
    }

    /** @return array{in_stock:int,with_cost:int,missing_cost:int,coverage:float} */
    public function coverage(Organization $organization): array
    {
        $base = ProductVariant::query()
            ->where('product_variants.organization_id', $organization->getKey())
            ->where('product_variants.status', 'active')
            ->whereHas('product', fn (Builder $query) => $query->where('status', 'active'))
            ->whereExists(fn ($balances) => $balances->selectRaw('1')->from('inventory_balances')
                ->whereColumn('inventory_balances.product_variant_id', 'product_variants.id')
                ->where('inventory_balances.organization_id', $organization->getKey())
                ->where('inventory_balances.on_hand', '>', 0));

        $inStock = (clone $base)->count();
        $withCost = (clone $base)->whereNotNull('purchase_price')->count();

        return [
            'in_stock' => $inStock,
            'with_cost' => $withCost,
            'missing_cost' => $inStock - $withCost,
            'coverage' => $inStock > 0 ? round(($withCost / $inStock) * 100, 1) : 0.0,
        ];
    }
}
