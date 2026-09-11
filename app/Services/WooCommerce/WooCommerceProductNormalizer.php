<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceIntegration;
use App\Services\CatalogImport\ExactDecimalParser;
use InvalidArgumentException;

/**
 * Converts a raw WooCommerce product (and, for variable products, its raw
 * variations) into a stable {@see NormalizedWooProduct} DTO.
 *
 * A per-product failure here (e.g. an unparseable price) throws
 * InvalidArgumentException so the sync engine can record it against that one
 * product and continue with the rest.
 */
class WooCommerceProductNormalizer
{
    public function __construct(private readonly ExactDecimalParser $prices) {}

    /**
     * @param  array<string, mixed>  $product
     * @param  list<array<string, mixed>>  $variations  raw Woo variation payloads (variable products only)
     */
    public function normalize(array $product, array $variations, WooCommerceIntegration $integration): NormalizedWooProduct
    {
        $type = (string) ($product['type'] ?? 'simple');
        $sku = $this->nullableString($product['sku'] ?? null);
        $reference = $this->metaValue($product['meta_data'] ?? [], $integration->reference_meta_key);

        $regular = $this->price($product['regular_price'] ?? null, $product['id'] ?? null);
        $sale = $this->price($product['sale_price'] ?? null, $product['id'] ?? null);
        $effective = $this->price($product['price'] ?? null, $product['id'] ?? null) ?? $regular;

        $variantDtos = [];
        if ($type === 'variable') {
            foreach ($variations as $variation) {
                $variantDtos[] = $this->normalizeVariation($variation, $integration, $product);
            }
        } else {
            // A simple product is one implicit ERP variant carrying the product-level price/stock.
            $variantDtos[] = new NormalizedWooVariant(
                remoteVariationId: null,
                status: (string) ($product['status'] ?? 'publish'),
                sku: $sku,
                reference: $reference,
                regularPrice: $regular,
                salePrice: $sale,
                effectivePrice: $effective,
                taxClass: $this->nullableString($product['tax_class'] ?? null),
                manageStock: (bool) ($product['manage_stock'] ?? false),
                stockQuantity: $this->intOrNull($product['stock_quantity'] ?? null),
                stockStatus: (string) ($product['stock_status'] ?? 'instock'),
                weight: $this->nullableString($product['weight'] ?? null),
                imageUrl: $this->firstImageUrl($product['images'] ?? []),
                attributes: [],
            );
        }

        return new NormalizedWooProduct(
            remoteId: (int) $product['id'],
            type: $type,
            status: (string) ($product['status'] ?? 'publish'),
            name: trim((string) ($product['name'] ?? '')) ?: "Produit WooCommerce #{$product['id']}",
            slug: $this->nullableString($product['slug'] ?? null),
            permalink: $this->nullableString($product['permalink'] ?? null),
            catalogVisibility: $this->nullableString($product['catalog_visibility'] ?? null),
            featured: (bool) ($product['featured'] ?? false),
            description: $this->nullableString($product['description'] ?? null),
            shortDescription: $this->nullableString($product['short_description'] ?? null),
            sku: $sku,
            reference: $reference,
            regularPrice: $regular,
            salePrice: $sale,
            effectivePrice: $effective,
            taxStatus: (string) ($product['tax_status'] ?? 'taxable'),
            taxClass: $this->nullableString($product['tax_class'] ?? null),
            manageStock: (bool) ($product['manage_stock'] ?? false),
            stockQuantity: $this->intOrNull($product['stock_quantity'] ?? null),
            stockStatus: (string) ($product['stock_status'] ?? 'instock'),
            backorders: $this->nullableString($product['backorders'] ?? null),
            weight: $this->nullableString($product['weight'] ?? null),
            shippingClass: $this->nullableString($product['shipping_class'] ?? null),
            brandName: (new WooBrandResolver)->resolve($product, $integration),
            imageUrl: $this->firstImageUrl($product['images'] ?? []),
            dateCreated: $this->nullableString($product['date_created_gmt'] ?? $product['date_created'] ?? null),
            dateModified: $this->nullableString($product['date_modified_gmt'] ?? $product['date_modified'] ?? null),
            virtual: (bool) ($product['virtual'] ?? false),
            downloadable: (bool) ($product['downloadable'] ?? false),
            onSale: (bool) ($product['on_sale'] ?? false),
            variants: $variantDtos,
            categories: $this->categories($product['categories'] ?? []),
            tags: array_values(array_filter(array_map(
                fn ($tag) => $this->nullableString($tag['name'] ?? null),
                $product['tags'] ?? [],
            ))),
        );
    }

    /**
     * @param  array<string, mixed>  $variation
     * @param  array<string, mixed>  $parent
     */
    private function normalizeVariation(array $variation, WooCommerceIntegration $integration, array $parent): NormalizedWooVariant
    {
        $regular = $this->price($variation['regular_price'] ?? null, $variation['id'] ?? null);
        $sale = $this->price($variation['sale_price'] ?? null, $variation['id'] ?? null);
        $effective = $this->price($variation['price'] ?? null, $variation['id'] ?? null) ?? $regular;

        $attributes = [];
        foreach ($variation['attributes'] ?? [] as $attribute) {
            $name = trim((string) ($attribute['name'] ?? ''));
            $value = trim((string) ($attribute['option'] ?? ''));
            if ($name !== '' && $value !== '') {
                $attributes[$name] = $value;
            }
        }

        return new NormalizedWooVariant(
            remoteVariationId: (int) $variation['id'],
            status: (string) ($variation['status'] ?? 'publish'),
            sku: $this->nullableString($variation['sku'] ?? null),
            reference: $this->metaValue($variation['meta_data'] ?? [], $integration->reference_meta_key),
            regularPrice: $regular,
            salePrice: $sale,
            effectivePrice: $effective,
            taxClass: $this->nullableString($variation['tax_class'] ?? $parent['tax_class'] ?? null),
            manageStock: (bool) ($variation['manage_stock'] ?? false),
            stockQuantity: $this->intOrNull($variation['stock_quantity'] ?? null),
            stockStatus: (string) ($variation['stock_status'] ?? 'instock'),
            weight: $this->nullableString($variation['weight'] ?? null),
            imageUrl: $this->firstImageUrl(isset($variation['image']) ? [$variation['image']] : [])
                ?? $this->firstImageUrl($parent['images'] ?? []),
            attributes: $attributes,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @return list<NormalizedWooCategory>
     */
    private function categories(array $categories): array
    {
        return array_values(array_filter(array_map(function ($category) {
            if (! isset($category['id'])) {
                return null;
            }

            return new NormalizedWooCategory(
                remoteId: (int) $category['id'],
                name: trim((string) ($category['name'] ?? '')) ?: "Catégorie #{$category['id']}",
                remoteParentId: null, // enriched from the store's full category tree during sync
            );
        }, $categories)));
    }

    /** @param list<array<string, mixed>> $images */
    private function firstImageUrl(array $images): ?string
    {
        foreach ($images as $image) {
            $src = trim((string) ($image['src'] ?? ''));
            if ($src !== '' && preg_match('#^https?://#i', $src)) {
                return $src;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $meta */
    private function metaValue(array $meta, ?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach ($meta as $entry) {
            if (($entry['key'] ?? null) === $key) {
                return $this->nullableString(is_scalar($entry['value'] ?? null) ? (string) $entry['value'] : null);
            }
        }

        return null;
    }

    private function price(mixed $value, mixed $remoteId): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return $this->prices->parse($raw);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("Woo #{$remoteId} — format de prix invalide (« {$raw} »).");
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
