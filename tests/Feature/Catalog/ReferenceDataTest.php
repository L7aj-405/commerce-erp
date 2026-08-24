<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Support\CatalogTestCase;

class ReferenceDataTest extends CatalogTestCase
{
    public function test_brand_category_unit_and_tax_rate_can_be_managed_in_active_tenant(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->actingAs($owner)->post(route('catalog.brands.store'), ['name' => 'Shure', 'slug' => 'shure', 'status' => 'active'])->assertRedirect();
        $this->actingAs($owner)->post(route('catalog.categories.store'), ['name' => 'Microphones', 'slug' => 'microphones', 'parent_id' => null, 'status' => 'active'])->assertRedirect();
        $this->actingAs($owner)->post(route('catalog.units.store'), ['name' => 'Piece', 'symbol' => 'pc', 'status' => 'active'])->assertRedirect();
        $this->actingAs($owner)->post(route('catalog.tax-rates.store'), ['name' => 'Configured Tax', 'rate' => '12.5000', 'status' => 'active'])->assertRedirect();

        $this->assertDatabaseHas('brands', ['organization_id' => $organization->id, 'slug' => 'shure']);
        $this->assertDatabaseHas('categories', ['organization_id' => $organization->id, 'slug' => 'microphones']);
        $this->assertDatabaseHas('units_of_measure', ['organization_id' => $organization->id, 'symbol' => 'pc']);
        $this->assertDatabaseHas('tax_rates', ['organization_id' => $organization->id, 'name' => 'Configured Tax']);
    }

    public function test_cross_tenant_parent_category_is_rejected(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $parentB = $this->createCategory($organizationB, 'Parent B');

        $this->actingAs($ownerA)->postJson(route('catalog.categories.store'), ['name' => 'Child A', 'slug' => 'child-a', 'parent_id' => $parentB->id, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseMissing('categories', ['organization_id' => $organizationA->id, 'slug' => 'child-a']);
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $category = $this->createCategory($organization);
        $this->actingAs($owner)->patchJson(route('catalog.categories.update', $category), ['name' => $category->name, 'slug' => $category->slug, 'parent_id' => $category->id, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }
}
