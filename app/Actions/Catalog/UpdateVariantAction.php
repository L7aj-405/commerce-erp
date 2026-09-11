<?php

namespace App\Actions\Catalog;

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AuditLogger;

class UpdateVariantAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, ProductVariant $variant, array $data): ProductVariant
    {
        $fields = ['label', 'sku', 'reference', 'barcode', 'purchase_price', 'regular_sale_price', 'promotional_sale_price', 'default_sale_price', 'public_price_ttc', 'unit_price_ht', 'tax_rate_id', 'status'];
        $oldValues = $variant->only($fields);
        foreach (['label', 'sku', 'reference', 'barcode', 'purchase_price', 'tax_rate_id', 'status'] as $field) {
            $variant->{$field} = $data[$field] ?? null;
        }
        $publicPrice = $data['public_price_ttc'] ?? $data['default_sale_price'] ?? $data['regular_sale_price'];
        $variant->public_price_ttc = $publicPrice;
        $variant->regular_sale_price = $data['regular_sale_price'] ?? $publicPrice;
        $variant->promotional_sale_price = ($data['promotional_sale_price'] ?? '') !== '' ? $data['promotional_sale_price'] : null;
        $variant->default_sale_price = $variant->promotional_sale_price ?? $variant->regular_sale_price;
        $variant->unit_price_ht = ($data['unit_price_ht'] ?? '') !== '' ? $data['unit_price_ht'] : null;
        $variant->save();

        $this->audit->record(
            'product_variant.updated',
            $actor,
            $variant->organization,
            auditable: $variant,
            oldValues: $oldValues,
            newValues: $variant->only($fields),
        );

        return $variant;
    }
}
