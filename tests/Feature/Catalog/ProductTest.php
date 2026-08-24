<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Support\CatalogTestCase;

class ProductTest extends CatalogTestCase
{
    public function test_product_and_initial_variant_are_created_for_active_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $brand = $this->createBrand($organization);
        $category = $this->createCategory($organization);
        $unit = $this->createUnit($organization);
        $tax = $this->createTaxRate($organization);

        $this->actingAs($owner)->post(route('catalog.products.store'), $this->productPayload([
            'brand_id' => $brand->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'variant' => ['tax_rate_id' => $tax->id],
        ]))->assertRedirect();

        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'New Product', 'brand_id' => $brand->id]);
        $this->assertDatabaseHas('product_variants', ['organization_id' => $organization->id, 'sku' => 'SKU-NEW', 'tax_rate_id' => $tax->id]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'product.created']);
    }

    public function test_product_can_be_updated_and_archived_without_deletion(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $product = $this->createProduct($organization);

        $this->actingAs($owner)->patch(route('catalog.products.update', $product), [
            'name' => 'Updated', 'description' => null, 'brand_id' => null, 'category_id' => null, 'unit_id' => null, 'status' => 'active',
            'organization_id' => 999999,
        ])->assertRedirect();
        $this->actingAs($owner)->patch(route('catalog.products.archive', $product))->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'organization_id' => $organization->id, 'name' => 'Updated', 'status' => 'inactive']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'status' => 'inactive']);
    }

    public function test_product_index_is_paginated(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        foreach (range(1, 16) as $number) {
            $this->createProduct($organization, "Product {$number}");
        }

        $this->actingAs($owner)->withHeader('X-Inertia', 'true')->get(route('catalog.products.index'))
            ->assertOk()->assertJsonCount(15, 'props.products.data')->assertJsonPath('props.products.total', 16);
    }
}
