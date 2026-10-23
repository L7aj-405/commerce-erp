<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Models\ProductVariant;
use App\Support\Decimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CatalogProductDataValidator
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function product(array $data, int $organizationId, bool $withVariant, bool $canManageCost): array
    {
        $rules = $this->productRules($organizationId);
        if ($withVariant) {
            $rules += ['variant' => ['required', 'array']];
            foreach ($this->variantRules($organizationId, null, $canManageCost, false) as $field => $rule) {
                $rules['variant.'.$field] = $rule;
            }
        }

        $validated = Validator::make($data, $rules)->validate();
        if (isset($validated['variant'])) {
            $validated['variant'] = $this->normalizeVariant($validated['variant']);
        }

        return $this->normalizeProduct($validated);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function variant(array $data, int $organizationId, ?ProductVariant $variant, bool $canManageCost): array
    {
        return $this->normalizeVariant(
            Validator::make($data, $this->variantRules($organizationId, $variant, $canManageCost))->validate(),
        );
    }

    /** @return array<string, mixed> */
    public function productRules(int $organizationId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'url:http,https', 'max:'.config('catalog_imports.image_url_max_length')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('organization_id', $organizationId)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organizationId)],
            'unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('organization_id', $organizationId)],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function variantRules(int $organizationId, ?ProductVariant $variant, bool $canManageCost, bool $requireStatus = true): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->where('organization_id', $organizationId)->ignore($variant)],
            'reference' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'reference')->where('organization_id', $organizationId)->ignore($variant)],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'barcode')->where('organization_id', $organizationId)->ignore($variant)],
            'image_url' => ['nullable', 'url:http,https', 'max:'.config('catalog_imports.image_url_max_length')],
            'purchase_price' => $canManageCost ? ['nullable', 'numeric', 'min:0', 'decimal:0,4'] : ['prohibited'],
            'public_price_ttc' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'required_without_all:regular_sale_price,default_sale_price'],
            'unit_price_ht' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'regular_sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'required_without_all:default_sale_price,public_price_ttc'],
            'promotional_sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'lte:regular_sale_price'],
            'default_sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'required_without_all:regular_sale_price,public_price_ttc'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('organization_id', $organizationId)],
            'status' => [$requireStatus ? 'required' : 'sometimes', Rule::enum(CatalogStatus::class)],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeProduct(array $data): array
    {
        $data['name'] = trim((string) $data['name']);
        $data['description'] = $this->nullableText($data['description'] ?? null);
        $data['image_url'] = $this->nullableText($data['image_url'] ?? null);

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeVariant(array $data): array
    {
        foreach (['label', 'reference', 'barcode', 'image_url'] as $field) {
            $data[$field] = $this->nullableText($data[$field] ?? null);
        }
        $data['sku'] = trim((string) $data['sku']);
        $data['status'] ??= CatalogStatus::Active->value;
        foreach (['purchase_price', 'public_price_ttc', 'unit_price_ht', 'regular_sale_price', 'promotional_sale_price', 'default_sale_price'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $data[$field] = Decimal::nonNegative((string) $data[$field], $field);
            } elseif (array_key_exists($field, $data)) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
