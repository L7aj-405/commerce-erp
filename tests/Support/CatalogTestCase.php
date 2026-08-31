<?php

namespace Tests\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use Illuminate\Support\Str;

abstract class CatalogTestCase extends PlatformTestCase
{
    protected function createBrand(Organization $organization, string $name = 'Brand'): Brand
    {
        $brand = new Brand;
        $brand->organization_id = $organization->id;
        $brand->name = $name;
        $brand->slug = Str::slug($name).'-'.Str::random(6);
        $brand->status = 'active';
        $brand->save();

        return $brand;
    }

    protected function createCategory(Organization $organization, string $name = 'Category', ?Category $parent = null): Category
    {
        $category = new Category;
        $category->organization_id = $organization->id;
        $category->parent_id = $parent?->id;
        $category->name = $name;
        $category->slug = Str::slug($name).'-'.Str::random(6);
        $category->status = 'active';
        $category->save();

        return $category;
    }

    protected function createUnit(Organization $organization, string $symbol = 'pc'): UnitOfMeasure
    {
        $unit = new UnitOfMeasure;
        $unit->organization_id = $organization->id;
        $unit->name = 'Piece';
        $unit->symbol = $symbol;
        $unit->status = 'active';
        $unit->save();

        return $unit;
    }

    protected function createTaxRate(Organization $organization, string $name = 'Tax', string $rate = '20.0000'): TaxRate
    {
        $tax = new TaxRate;
        $tax->organization_id = $organization->id;
        $tax->name = $name;
        $tax->rate = $rate;
        $tax->status = 'active';
        $tax->save();

        return $tax;
    }

    protected function createProduct(Organization $organization, string $name = 'Product', ?string $sku = null, array $attributes = []): Product
    {
        $product = new Product;
        $product->organization_id = $organization->id;
        $product->name = $name;
        $product->description = $attributes['description'] ?? null;
        $product->image_url = $attributes['image_url'] ?? null;
        $product->brand_id = $attributes['brand_id'] ?? null;
        $product->default_category_id = $attributes['category_id'] ?? null;
        $product->default_unit_id = $attributes['unit_id'] ?? null;
        $product->status = $attributes['status'] ?? 'active';
        $product->save();

        $variant = new ProductVariant;
        $variant->organization_id = $organization->id;
        $variant->product_id = $product->id;
        $variant->label = $attributes['label'] ?? null;
        $variant->sku = $sku ?? 'SKU-'.Str::upper(Str::random(8));
        $variant->reference = $attributes['reference'] ?? null;
        $variant->barcode = $attributes['barcode'] ?? null;
        $variant->purchase_price = $attributes['purchase_price'] ?? null;
        $variant->default_sale_price = $attributes['default_sale_price'] ?? '100.0000';
        $variant->regular_sale_price = $attributes['regular_sale_price'] ?? $variant->default_sale_price;
        $variant->promotional_sale_price = $attributes['promotional_sale_price'] ?? null;
        $variant->tax_rate_id = $attributes['tax_rate_id'] ?? null;
        $variant->status = $attributes['variant_status'] ?? 'active';
        $variant->save();

        return $product->load('variants');
    }

    protected function productPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'New Product', 'description' => 'Catalog item', 'image_url' => null, 'brand_id' => null, 'category_id' => null, 'unit_id' => null, 'status' => 'active',
            'variant' => ['label' => null, 'sku' => 'SKU-NEW', 'reference' => 'REF-NEW', 'barcode' => null, 'purchase_price' => '50.0000', 'default_sale_price' => '100.0000', 'tax_rate_id' => null, 'status' => 'active'],
        ], $overrides);
    }
}
