<?php

namespace App\Services\Mcp\Products;

use App\Enums\CatalogStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductVariant;
use App\Services\ProductPriceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class ProductCatalogReadService
{
    public function __construct(private readonly ProductPriceResolver $prices) {}

    /**
     * @param array{query?: string|null, status?: string|null, brand_id?: int|null, category_id?: int|null, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters): array
    {
        $organizationId = (int) $organization->getKey();
        $queryText = trim((string) ($filters['query'] ?? ''));
        $status = $filters['status'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);

        if (mb_strlen($queryText) > 255) {
            throw new InvalidArgumentException('query_too_long');
        }
        if ($status !== null && CatalogStatus::tryFrom((string) $status) === null) {
            throw new InvalidArgumentException('invalid_status');
        }
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('invalid_pagination');
        }

        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->with([
                'brand' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'name']),
                'defaultCategory' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'name']),
                'variants' => fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->select(['id', 'organization_id', 'product_id', 'label', 'sku', 'reference', 'barcode', 'status'])
                    ->orderBy('id'),
            ])
            ->when($queryText !== '', function ($query) use ($queryText, $organizationId) {
                $escaped = addcslashes($queryText, '\\%_');
                $like = "%{$escaped}%";

                $query->where(function ($query) use ($like, $organizationId) {
                    $query->where('name', 'like', $like)
                        ->orWhereHas('variants', fn ($variants) => $variants
                            ->where('organization_id', $organizationId)
                            ->where(fn ($identifiers) => $identifiers
                                ->where('sku', 'like', $like)
                                ->orWhere('reference', 'like', $like)
                                ->orWhere('barcode', 'like', $like)));
                });
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(isset($filters['brand_id']), fn ($query) => $query->where('brand_id', (int) $filters['brand_id']))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('default_category_id', (int) $filters['category_id']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->searchPayload($products);
    }

    /** @return array<string, mixed> */
    public function product(Organization $organization, int $productId, bool $includePurchaseCost): array
    {
        $organizationId = (int) $organization->getKey();
        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->whereKey($productId)
            ->with([
                'brand' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'name']),
                'defaultCategory' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'name']),
                'defaultUnit' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'name', 'symbol']),
                'channelIdentifiers' => fn ($query) => $query->where('organization_id', $organizationId),
                'variants' => fn ($query) => $query->where('organization_id', $organizationId)->orderBy('id'),
                'variants.taxRate' => fn ($query) => $query->where('organization_id', $organizationId),
                'variants.channelIdentifiers' => fn ($query) => $query->where('organization_id', $organizationId),
            ])
            ->first();

        if (! $product) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        $defaultTaxRate = $this->prices->defaultTaxRate(null, $organizationId);

        return [
            'id' => $product->getKey(),
            'name' => $product->name,
            'description' => $product->description,
            'image_url' => $product->image_url,
            'status' => $this->status($product->status),
            'brand' => $product->brand ? ['id' => $product->brand->getKey(), 'name' => $product->brand->name] : null,
            'category' => $product->defaultCategory ? ['id' => $product->defaultCategory->getKey(), 'name' => $product->defaultCategory->name] : null,
            'unit' => $product->defaultUnit ? [
                'id' => $product->defaultUnit->getKey(),
                'name' => $product->defaultUnit->name,
                'symbol' => $product->defaultUnit->symbol,
            ] : null,
            'channel_metadata' => $product->channelIdentifiers->map(fn (ProductChannelIdentifier $identifier) => $this->channelMetadata($identifier))->values()->all(),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => $this->variantPayload(
                $variant,
                $this->prices->resolveWith($variant, $defaultTaxRate),
                $includePurchaseCost,
            ))->values()->all(),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function variant(Organization $organization, int $variantId, bool $includePurchaseCost): array
    {
        $organizationId = (int) $organization->getKey();
        $variant = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->whereKey($variantId)
            ->with([
                'product' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'organization_id', 'name', 'status']),
                'taxRate' => fn ($query) => $query->where('organization_id', $organizationId),
                'channelIdentifiers' => fn ($query) => $query->where('organization_id', $organizationId),
            ])
            ->first();

        if (! $variant) {
            throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
        }

        $payload = $this->variantPayload(
            $variant,
            $this->prices->resolveWith(
                $variant,
                $this->prices->defaultTaxRate(null, $organizationId),
            ),
            $includePurchaseCost,
        );
        $payload['product'] = [
            'id' => $variant->product->getKey(),
            'name' => $variant->product->name,
            'status' => $this->status($variant->product->status),
        ];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function searchPayload(LengthAwarePaginator $products): array
    {
        return [
            'items' => collect($products->items())->map(fn (Product $product) => [
                'id' => $product->getKey(),
                'name' => $product->name,
                'status' => $this->status($product->status),
                'brand' => $product->brand ? ['id' => $product->brand->getKey(), 'name' => $product->brand->name] : null,
                'category' => $product->defaultCategory ? ['id' => $product->defaultCategory->getKey(), 'name' => $product->defaultCategory->name] : null,
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    'id' => $variant->getKey(),
                    'label' => $variant->label,
                    'sku' => $variant->sku,
                    'reference' => $variant->reference,
                    'barcode' => $variant->barcode,
                    'status' => $this->status($variant->status),
                ])->values()->all(),
            ])->values()->all(),
            'pagination' => [
                'page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more' => $products->hasMorePages(),
                'next_page' => $products->hasMorePages() ? $products->currentPage() + 1 : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $resolvedPrice
     * @return array<string, mixed>
     */
    private function variantPayload(ProductVariant $variant, array $resolvedPrice, bool $includePurchaseCost): array
    {
        $payload = [
            'id' => $variant->getKey(),
            'label' => $variant->label,
            'sku' => $variant->sku,
            'reference' => $variant->reference,
            'barcode' => $variant->barcode,
            'status' => $this->status($variant->status),
            'pricing' => [
                'unit_price_ht' => $resolvedPrice['unit_price_ht'],
                'unit_price_ttc' => $resolvedPrice['unit_price_ttc'],
                'regular_sale_price' => $this->decimal($variant->regular_sale_price),
                'promotional_sale_price' => $this->decimal($variant->promotional_sale_price),
                'tax' => [
                    'id' => $resolvedPrice['tax_rate_id'],
                    'name' => $resolvedPrice['tax_name'],
                    'rate' => $resolvedPrice['tax_rate_value'],
                    'source' => $resolvedPrice['tax_source'],
                ],
                'ht_source' => $resolvedPrice['ht_source'],
                'config_missing' => $resolvedPrice['config_missing'],
            ],
            'channel_metadata' => $variant->channelIdentifiers->map(fn (ProductChannelIdentifier $identifier) => $this->channelMetadata($identifier))->values()->all(),
            'created_at' => $variant->created_at?->toIso8601String(),
            'updated_at' => $variant->updated_at?->toIso8601String(),
        ];

        if ($includePurchaseCost) {
            $payload['purchase_cost'] = $this->decimal($variant->purchase_price);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function channelMetadata(ProductChannelIdentifier $identifier): array
    {
        return [
            'source' => $identifier->source,
            'external_product_id' => $identifier->external_product_id,
            'remote_parent_id' => $identifier->remote_parent_id,
            'remote_variation_id' => $identifier->remote_variation_id,
            'stock_status' => $identifier->external_stock_status,
            'remote_modified_at' => $identifier->remote_modified_at?->toIso8601String(),
            'last_synced_at' => $identifier->last_synced_at?->toIso8601String(),
        ];
    }

    private function status(mixed $status): string
    {
        return $status instanceof CatalogStatus ? $status->value : (string) $status;
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
