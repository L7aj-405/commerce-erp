<?php

namespace App\Services\Mcp\Products;

use App\Enums\CatalogStatus;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Variant-level purchase-cost coverage of in-stock variants. Stock is the
 * organization-wide sum of inventory balances (available = on_hand − reserved),
 * the same source ProductStockReadService reports. purchase_price NULL means
 * "missing"; an explicit 0.0000 counts as a recorded cost.
 *
 * Fixed query count: one aggregate for the summary, the paginator's count and
 * page queries, one balance query for the page's stock breakdown.
 */
final class PurchaseCostCoverageService
{
    public function __construct(private readonly ProductStockReadService $stock) {}

    /**
     * @param array{stock_basis?: string, status?: string|null, purchase_cost_status?: string, query?: string|null, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function coverage(Organization $organization, array $filters): array
    {
        $organizationId = (int) $organization->getKey();
        $basis = $filters['stock_basis'] ?? 'available';
        $status = $filters['status'] ?? null;
        $costStatus = $filters['purchase_cost_status'] ?? 'all';
        $queryText = trim((string) ($filters['query'] ?? ''));
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);

        if (! in_array($basis, ['available', 'on_hand'], true)) throw new InvalidArgumentException('invalid_stock_basis');
        if ($status !== null && CatalogStatus::tryFrom((string) $status) === null) throw new InvalidArgumentException('invalid_status');
        if (! in_array($costStatus, ['all', 'with_cost', 'without_cost'], true)) throw new InvalidArgumentException('invalid_purchase_cost_status');
        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');

        $stock = DB::table('inventory_balances')
            ->where('organization_id', $organizationId)
            ->groupBy('product_variant_id')
            ->select('product_variant_id')
            ->selectRaw('SUM(on_hand) AS on_hand_total, SUM(on_hand - reserved) AS available_total');

        $base = ProductVariant::query()
            ->joinSub($stock, 'stock', 'stock.product_variant_id', '=', 'product_variants.id')
            ->join('products', fn ($join) => $join
                ->on('products.id', '=', 'product_variants.product_id')
                ->on('products.organization_id', '=', 'product_variants.organization_id'))
            ->where('product_variants.organization_id', $organizationId)
            ->where($basis === 'on_hand' ? 'stock.on_hand_total' : 'stock.available_total', '>', 0)
            ->when($status !== null, fn ($query) => $query->where('product_variants.status', $status))
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('products.name', 'like', $like)
                    ->orWhere('product_variants.sku', 'like', $like)
                    ->orWhere('product_variants.reference', 'like', $like)
                    ->orWhere('product_variants.barcode', 'like', $like));
            });

        // Summary over ALL matching in-stock variants, before cost filter and pagination.
        $summary = (clone $base)->toBase()
            ->selectRaw('COUNT(*) AS total, COUNT(product_variants.purchase_price) AS with_cost')
            ->first();
        $total = (int) ($summary->total ?? 0);
        $withCost = (int) ($summary->with_cost ?? 0);

        $variants = (clone $base)
            ->when($costStatus === 'with_cost', fn ($query) => $query->whereNotNull('product_variants.purchase_price'))
            ->when($costStatus === 'without_cost', fn ($query) => $query->whereNull('product_variants.purchase_price'))
            ->select([
                'product_variants.id', 'product_variants.product_id', 'product_variants.label', 'product_variants.sku',
                'product_variants.reference', 'product_variants.barcode', 'product_variants.purchase_price',
                'products.name AS product_name', 'products.status AS product_status',
            ])
            ->orderBy('products.name')->orderBy('products.id')->orderBy('product_variants.id')
            ->paginate($perPage, ['*'], 'page', $page);

        $stockByVariant = $this->stock->variantTotals($organization, collect($variants->items())->map(fn (ProductVariant $variant) => (int) $variant->getKey())->all());

        return [
            'summary' => [
                'stock_basis' => $basis,
                'total_in_stock_variants' => $total,
                'with_purchase_cost' => $withCost,
                'without_purchase_cost' => $total - $withCost,
                // Two decimals; null when no variant matches (0/0 is undefined).
                'coverage_percent' => $total === 0 ? null : Decimal::round(Decimal::divide($withCost * 100, $total), 2),
            ],
            'filters' => ['status' => $status, 'purchase_cost_status' => $costStatus, 'query' => $queryText === '' ? null : $queryText],
            'items' => collect($variants->items())->map(fn (ProductVariant $variant) => [
                'product_id' => (int) $variant->product_id,
                'product_name' => $variant->getAttribute('product_name'),
                'product_status' => (string) $variant->getAttribute('product_status'),
                'variant_id' => (int) $variant->getKey(),
                'variant_label' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
                'barcode' => $variant->barcode,
                'purchase_cost' => $variant->purchase_price === null ? null : Decimal::normalize((string) $variant->purchase_price),
                'stock' => $stockByVariant[(int) $variant->getKey()],
            ])->values()->all(),
            'pagination' => [
                'page' => $variants->currentPage(),
                'per_page' => $variants->perPage(),
                'total' => $variants->total(),
                'has_more' => $variants->hasMorePages(),
                'next_page' => $variants->hasMorePages() ? $variants->currentPage() + 1 : null,
            ],
        ];
    }
}
