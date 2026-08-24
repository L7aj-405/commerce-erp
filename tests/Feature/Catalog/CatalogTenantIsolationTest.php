<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Support\CatalogTestCase;

class CatalogTenantIsolationTest extends CatalogTestCase
{
    public function test_organization_a_cannot_view_update_or_archive_product_b(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $productB = $this->createProduct($organizationB, 'Private B');
        $this->activate($ownerA, $organizationA);

        $this->actingAs($ownerA)->withHeader('X-Inertia', 'true')->get(route('catalog.products.show', $productB))->assertNotFound();
        $this->actingAs($ownerA)->patch(route('catalog.products.update', $productB), ['name' => 'Forged'])->assertNotFound();
        $this->actingAs($ownerA)->patch(route('catalog.products.archive', $productB))->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $productB->id, 'name' => 'Private B', 'status' => 'active']);
    }

    public function test_product_search_never_leaks_matching_product_from_another_tenant(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $this->createProduct($organizationA, 'Visible Needle');
        $productB = $this->createProduct($organizationB, 'Secret Needle');

        $response = $this->actingAs($ownerA)->withHeader('X-Inertia', 'true')->get(route('catalog.products.index', ['search' => 'Needle']))->assertOk();
        $response->assertJsonFragment(['name' => 'Visible Needle'])->assertJsonMissing(['id' => $productB->id, 'name' => 'Secret Needle']);
    }

    public function test_exact_foreign_catalog_ids_do_not_bypass_active_tenant_binding(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $brandB = $this->createBrand($organizationB, 'Brand B');
        $this->activate($ownerA, $organizationA);

        $this->actingAs($ownerA)->patch(route('catalog.brands.update', $brandB), ['name' => 'Forged', 'slug' => 'forged', 'status' => 'active'])->assertNotFound();
    }
}
