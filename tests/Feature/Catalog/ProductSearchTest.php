<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Support\CatalogTestCase;

class ProductSearchTest extends CatalogTestCase
{
    public function test_search_matches_name_sku_reference_barcode_and_brand(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $brand = $this->createBrand($organization, 'Searchable Brand');
        $this->createProduct($organization, 'Alpha Microphone', 'SKU-ALPHA', ['brand_id' => $brand->id, 'reference' => 'REF-7788', 'barcode' => '6111111111111']);

        foreach (['Alpha Microphone', 'SKU-ALPHA', 'REF-7788', '6111111111111', 'Searchable Brand'] as $search) {
            $this->actingAs($owner)->withHeader('X-Inertia', 'true')->get(route('catalog.products.index', ['search' => $search]))
                ->assertOk()->assertJsonFragment(['name' => 'Alpha Microphone']);
        }
    }

    public function test_status_brand_and_category_filters_are_tenant_scoped(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $brand = $this->createBrand($organization);
        $category = $this->createCategory($organization);
        $this->createProduct($organization, 'Matching', attributes: ['brand_id' => $brand->id, 'category_id' => $category->id]);
        $this->createProduct($organization, 'Not Matching', attributes: ['status' => 'inactive']);

        $this->actingAs($owner)->withHeader('X-Inertia', 'true')->get(route('catalog.products.index', ['status' => 'active', 'brand' => $brand->id, 'category' => $category->id]))
            ->assertOk()->assertJsonFragment(['name' => 'Matching'])->assertJsonMissing(['name' => 'Not Matching']);
    }
}
