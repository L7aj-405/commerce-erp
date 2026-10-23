<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductVariant;

final class WooCommerceProductFieldOwnership
{
    private const PRODUCT_FIELDS = ['name', 'description', 'image_url', 'brand_id', 'category_id', 'status'];
    private const VARIANT_FIELDS = [
        'label', 'sku', 'reference', 'image_url', 'public_price_ttc', 'unit_price_ht',
        'regular_sale_price', 'promotional_sale_price', 'default_sale_price', 'tax_rate_id', 'status',
    ];

    /** @param list<string> $fields @return list<string> */
    public function productConflicts(Product $product, array $fields): array
    {
        return $this->isWooManagedProduct($product)
            ? array_values(array_intersect($fields, self::PRODUCT_FIELDS))
            : [];
    }

    /** @param list<string> $fields @return list<string> */
    public function variantConflicts(ProductVariant $variant, array $fields): array
    {
        return $this->isWooManagedVariant($variant)
            ? array_values(array_intersect($fields, self::VARIANT_FIELDS))
            : [];
    }

    public function isWooManagedProduct(Product $product): bool
    {
        return ProductChannelIdentifier::query()
            ->where('organization_id', $product->organization_id)
            ->where('product_id', $product->getKey())
            ->where('source', 'woocommerce')
            ->exists();
    }

    public function isWooManagedVariant(ProductVariant $variant): bool
    {
        return ProductChannelIdentifier::query()
            ->where('organization_id', $variant->organization_id)
            ->where('product_variant_id', $variant->getKey())
            ->where('source', 'woocommerce')
            ->exists();
    }
}
