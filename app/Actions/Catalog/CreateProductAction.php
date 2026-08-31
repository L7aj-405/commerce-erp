<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateProductAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data): Product
    {
        return DB::transaction(function () use ($actor, $organization, $data) {
            $product = new Product;
            $product->organization_id = $organization->getKey();
            $product->name = $data['name'];
            $product->description = $data['description'] ?? null;
            $product->image_url = $data['image_url'] ?? null;
            $product->brand_id = $data['brand_id'] ?? null;
            $product->default_category_id = $data['category_id'] ?? null;
            $product->default_unit_id = $data['unit_id'] ?? null;
            $product->status = $data['status'] ?? CatalogStatus::Active->value;
            $product->save();

            $variantData = $data['variant'];
            $variant = new ProductVariant;
            $variant->organization_id = $organization->getKey();
            $variant->product_id = $product->getKey();
            $variant->label = $variantData['label'] ?? null;
            $variant->sku = $variantData['sku'];
            $variant->reference = $variantData['reference'] ?? null;
            $variant->barcode = $variantData['barcode'] ?? null;
            $variant->purchase_price = $variantData['purchase_price'] ?? null;
            $variant->regular_sale_price = $variantData['regular_sale_price'] ?? $variantData['default_sale_price'];
            $variant->promotional_sale_price = ($variantData['promotional_sale_price'] ?? '') !== '' ? $variantData['promotional_sale_price'] : null;
            $variant->default_sale_price = $variant->promotional_sale_price ?? $variant->regular_sale_price;
            $variant->tax_rate_id = $variantData['tax_rate_id'] ?? null;
            $variant->status = $variantData['status'] ?? CatalogStatus::Active->value;
            $variant->save();

            $this->audit->record(
                'product.created',
                $actor,
                $organization,
                auditable: $product,
                newValues: $product->only(['name', 'image_url', 'brand_id', 'default_category_id', 'default_unit_id', 'status']),
            );
            $this->audit->record(
                'product_variant.created',
                $actor,
                $organization,
                auditable: $variant,
                newValues: $variant->only(['product_id', 'sku', 'reference', 'barcode', 'purchase_price', 'regular_sale_price', 'promotional_sale_price', 'default_sale_price', 'tax_rate_id', 'status']),
            );

            return $product->load('variants');
        });
    }
}
