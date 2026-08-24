<?php

namespace Tests\Support;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Str;

abstract class PosTestCase extends SalesTestCase
{
    /** @return array<string, mixed> */
    protected function posPayload(Warehouse $warehouse, array $lines, array $overrides = []): array
    {
        return array_replace([
            'client_operation_id' => (string) Str::uuid(),
            'warehouse_id' => $warehouse->getKey(),
            'customer_id' => null,
            'lines' => $lines,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function posCatalogLine(ProductVariant $variant, array $overrides = []): array
    {
        return array_replace([
            'line_type' => 'catalog',
            'product_variant_id' => $variant->getKey(),
            'quantity' => '1.0000',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function posCustomLine(array $overrides = []): array
    {
        return array_replace([
            'line_type' => 'custom',
            'description' => 'POS service',
            'reference' => null,
            'unit_label' => 'item',
            'quantity' => '1.0000',
            'unit_price_excl_tax' => '100.0000',
            'tax_rate_id' => null,
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ], $overrides);
    }
}
