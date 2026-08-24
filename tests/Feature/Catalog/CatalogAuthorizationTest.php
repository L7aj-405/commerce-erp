<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Support\CatalogTestCase;

class CatalogAuthorizationTest extends CatalogTestCase
{
    public function test_user_with_products_view_can_view_and_user_without_it_cannot(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $denied = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $product = $this->createProduct($organization);
        $this->addOrganizationMember($organization, $viewer, ['catalog.view', 'products.view']);
        $this->addOrganizationMember($organization, $denied, ['catalog.view']);
        $this->activate($viewer, $organization);
        $this->activate($denied, $organization);

        $this->actingAs($viewer)->withHeader('X-Inertia', 'true')->get(route('catalog.products.show', $product))->assertOk();
        $this->actingAs($denied)->withHeader('X-Inertia', 'true')->get(route('catalog.products.show', $product))->assertForbidden();
    }

    public function test_users_without_create_update_or_archive_permissions_are_denied_before_mutation(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $product = $this->createProduct($organization, 'Original');
        $this->addOrganizationMember($organization, $viewer, ['catalog.view', 'products.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->post(route('catalog.products.store'), $this->productPayload(['name' => 'Denied Create']))->assertForbidden();
        $this->actingAs($viewer)->patch(route('catalog.products.update', $product), ['name' => 'Denied Update'])->assertForbidden();
        $this->actingAs($viewer)->patch(route('catalog.products.archive', $product))->assertForbidden();
        $this->assertDatabaseMissing('products', ['organization_id' => $organization->id, 'name' => 'Denied Create']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Original', 'status' => 'active']);
    }

    public function test_catalog_permissions_remain_organization_scoped(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $member = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $productA = $this->createProduct($organizationA, 'Product A');
        $productB = $this->createProduct($organizationB, 'Product B');
        $this->addOrganizationMember($organizationA, $member, ['products.view', 'products.update']);
        $this->addOrganizationMember($organizationB, $member, ['products.view']);

        $this->activate($member, $organizationA);
        $this->actingAs($member)->patch(route('catalog.products.update', $productA), ['name' => 'Updated A', 'description' => null, 'brand_id' => null, 'category_id' => null, 'unit_id' => null, 'status' => 'active'])->assertRedirect();
        $this->activate($member, $organizationB);
        $this->actingAs($member)->patch(route('catalog.products.update', $productB), ['name' => 'Forged B'])->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $productB->id, 'name' => 'Product B']);
    }

    public function test_reference_data_manage_permission_is_separate_from_view(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['brands.view']);
        $this->activate($viewer, $organization);
        $this->actingAs($viewer)->withHeader('X-Inertia', 'true')->get(route('catalog.brands.index'))->assertOk();
        $this->actingAs($viewer)->post(route('catalog.brands.store'), ['name' => 'Denied', 'slug' => 'denied', 'status' => 'active'])->assertForbidden();
    }
}
