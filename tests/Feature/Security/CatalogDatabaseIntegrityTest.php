<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\Support\CatalogTestCase;

class CatalogDatabaseIntegrityTest extends CatalogTestCase
{
    public function test_sku_is_unique_within_organization_but_reusable_across_organizations(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $this->createProduct($organizationA, 'A Product', 'SHARED-SKU');
        $this->createProduct($organizationB, 'B Product', 'SHARED-SKU');
        $this->assertDatabaseCount('product_variants', 2);
    }

    public function test_duplicate_sku_in_same_organization_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createProduct($organization, 'First', 'DUPLICATE');
        $this->expectException(QueryException::class);
        $this->createProduct($organization, 'Second', 'DUPLICATE');
    }

    public function test_database_rejects_cross_tenant_product_brand_relationship(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $brandB = $this->createBrand($organizationB);
        $this->expectException(QueryException::class);
        $product = new Product;
        $product->organization_id = $organizationA->id;
        $product->name = 'Invalid';
        $product->brand_id = $brandB->id;
        $product->status = 'active';
        $product->save();
    }

    public function test_database_rejects_cross_tenant_variant_product_and_tax_relationships(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $productB = $this->createProduct($organizationB);
        $taxA = $this->createTaxRate($organizationA);
        $this->expectException(QueryException::class);
        $variant = new ProductVariant;
        $variant->organization_id = $organizationA->id;
        $variant->product_id = $productB->id;
        $variant->sku = 'INVALID';
        $variant->default_sale_price = '1.0000';
        $variant->tax_rate_id = $taxA->id;
        $variant->status = 'active';
        $variant->save();
    }

    public function test_database_rejects_cross_tenant_category_parent(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $parentB = $this->createCategory($organizationB);
        $this->expectException(QueryException::class);
        $child = new Category;
        $child->organization_id = $organizationA->id;
        $child->parent_id = $parentB->id;
        $child->name = 'Invalid';
        $child->slug = 'invalid';
        $child->status = 'active';
        $child->save();
    }

    public function test_nullable_barcode_allows_multiple_nulls_but_non_null_barcode_is_tenant_unique(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createProduct($organization, 'No Barcode One');
        $this->createProduct($organization, 'No Barcode Two');
        $this->createProduct($organization, 'Barcode One', attributes: ['barcode' => '123456']);
        $this->expectException(QueryException::class);
        $this->createProduct($organization, 'Barcode Duplicate', attributes: ['barcode' => '123456']);
    }
}
