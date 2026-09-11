<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceIntegration;

/**
 * WooCommerce core has no universal Brand field. Brands may live in a brand
 * taxonomy (added by WooCommerce Brands / a plugin), a product attribute, or a
 * custom meta key. The source is configured per integration; if nothing is
 * configured or nothing matches, the ERP Brand is simply left empty — never invented.
 *
 * @param  array<string, mixed>  $product
 */
class WooBrandResolver
{
    public function resolve(array $product, WooCommerceIntegration $integration): ?string
    {
        return match ($integration->brand_source) {
            'taxonomy' => $this->fromTaxonomy($product, $integration->brand_taxonomy),
            'attribute' => $this->fromAttribute($product, $integration->brand_attribute_name),
            'meta' => $this->fromMeta($product, $integration->brand_meta_key),
            default => $this->autoDetect($product),
        };
    }

    /** @param array<string, mixed> $product */
    private function autoDetect(array $product): ?string
    {
        // Common brand taxonomies exposed inline by popular plugins.
        foreach (['brands', 'product_brand', 'pwb-brand', 'yith_product_brand'] as $key) {
            $value = $this->firstTermName($product[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        // A product attribute literally named "Brand" / "Marque".
        return $this->fromAttribute($product, 'brand') ?? $this->fromAttribute($product, 'marque');
    }

    /** @param array<string, mixed> $product */
    private function fromTaxonomy(array $product, ?string $taxonomy): ?string
    {
        if ($taxonomy === null || $taxonomy === '') {
            return null;
        }

        return $this->firstTermName($product[$taxonomy] ?? null);
    }

    /** @param array<string, mixed> $product */
    private function fromAttribute(array $product, ?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $needle = mb_strtolower(trim($name));
        foreach ($product['attributes'] ?? [] as $attribute) {
            if (mb_strtolower(trim((string) ($attribute['name'] ?? ''))) !== $needle) {
                continue;
            }
            $options = $attribute['options'] ?? [];
            $first = is_array($options) ? ($options[0] ?? null) : $options;
            $value = trim((string) ($first ?? ''));

            return $value === '' ? null : $value;
        }

        return null;
    }

    /** @param array<string, mixed> $product */
    private function fromMeta(array $product, ?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach ($product['meta_data'] ?? [] as $entry) {
            if (($entry['key'] ?? null) === $key && is_scalar($entry['value'] ?? null)) {
                $value = trim((string) $entry['value']);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    private function firstTermName(mixed $terms): ?string
    {
        if (! is_array($terms)) {
            return null;
        }

        foreach ($terms as $term) {
            $name = is_array($term) ? trim((string) ($term['name'] ?? '')) : trim((string) $term);
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
