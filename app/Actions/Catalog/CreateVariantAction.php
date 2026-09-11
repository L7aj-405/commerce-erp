<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AuditLogger;

class CreateVariantAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Product $product, array $data): ProductVariant
    {
        $variant = new ProductVariant;
        $variant->organization_id = $product->organization_id;
        $variant->product_id = $product->getKey();
        $variant->label = $data['label'] ?? null;
        $variant->sku = $data['sku'];
        $variant->reference = $data['reference'] ?? null;
        $variant->barcode = $data['barcode'] ?? null;
        $variant->purchase_price = $data['purchase_price'] ?? null;
        $publicPrice = $data['public_price_ttc'] ?? $data['default_sale_price'] ?? $data['regular_sale_price'];
        $variant->public_price_ttc = $publicPrice;
        $variant->regular_sale_price = $data['regular_sale_price'] ?? $publicPrice;
        $variant->promotional_sale_price = ($data['promotional_sale_price'] ?? '') !== '' ? $data['promotional_sale_price'] : null;
        $variant->default_sale_price = $variant->promotional_sale_price ?? $variant->regular_sale_price;
        $variant->unit_price_ht = ($data['unit_price_ht'] ?? '') !== '' ? $data['unit_price_ht'] : null;
        $variant->tax_rate_id = $data['tax_rate_id'] ?? null;
        $variant->status = $data['status'] ?? CatalogStatus::Active->value;
        $variant->save();

        $this->audit->record('product_variant.created', $actor, $product->organization, auditable: $variant, newValues: $variant->only([
            'product_id', 'sku', 'reference', 'barcode', 'purchase_price', 'regular_sale_price', 'promotional_sale_price', 'default_sale_price', 'public_price_ttc', 'unit_price_ht', 'tax_rate_id', 'status',
        ]));

        return $variant;
    }
}
