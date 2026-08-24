<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\User;

class CatalogIdorAttackTest extends TenantRedTeamTestCase
{
    public function test_user_with_multiple_organizations_cannot_access_product_outside_active_context(): void
    {
        $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.show', $this->productB))
            ->assertNotFound();
        $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.edit', $this->productB))
            ->assertNotFound();
        $this->actingAs($this->userA)
            ->patch(route('catalog.products.update', $this->productB), ['name' => 'IDOR Mutation'])
            ->assertNotFound();
        $this->actingAs($this->userA)
            ->patch(route('catalog.products.archive', $this->productB))
            ->assertNotFound();

        $this->assertDatabaseHas('products', [
            'id' => $this->productB->id,
            'name' => 'RedTeam Product B',
            'status' => 'active',
        ]);
    }

    public function test_switching_active_organization_reverses_which_products_are_visible(): void
    {
        $this->actingAs($this->userA)
            ->post(route('context.organization', ['organizationId' => $this->organizationB->id]))
            ->assertRedirect();

        $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.show', $this->productB))
            ->assertOk()
            ->assertJsonPath('props.product.id', $this->productB->id);
        $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.show', $this->productA))
            ->assertNotFound();
    }

    public function test_unrelated_user_cannot_bind_catalog_resources_from_organizations_a_or_b(): void
    {
        foreach ([$this->productA, $this->productB] as $product) {
            $this->actingAs($this->userB)
                ->withHeader('X-Inertia', 'true')
                ->get(route('catalog.products.show', $product))
                ->assertNotFound();
            $this->actingAs($this->userB)
                ->patch(route('catalog.products.archive', $product))
                ->assertNotFound();
        }

        foreach ([
            [route('catalog.brands.update', $this->brandA), ['name' => 'Attack', 'slug' => 'attack', 'status' => 'active']],
            [route('catalog.categories.update', $this->categoryB), ['name' => 'Attack', 'slug' => 'attack', 'parent_id' => null, 'status' => 'active']],
            [route('catalog.units.update', $this->unitA), ['name' => 'Attack', 'symbol' => 'attack', 'status' => 'active']],
            [route('catalog.tax-rates.update', $this->taxB), ['name' => 'Attack', 'rate' => '1.0000', 'status' => 'active']],
        ] as [$route, $payload]) {
            $this->actingAs($this->userB)->patch($route, $payload)->assertNotFound();
        }

        $this->assertDatabaseHas('brands', ['id' => $this->brandA->id, 'name' => 'RedTeam Brand A']);
        $this->assertDatabaseHas('categories', ['id' => $this->categoryB->id, 'name' => 'RedTeam Category B']);
        $this->assertDatabaseHas('units_of_measure', ['id' => $this->unitA->id, 'name' => 'RedTeam Unit A']);
        $this->assertDatabaseHas('tax_rates', ['id' => $this->taxB->id, 'name' => 'RedTeam Tax B']);
    }

    public function test_unrelated_organization_and_store_ids_are_hidden_by_route_binding(): void
    {
        $this->actingAs($this->userB)
            ->get(route('organizations.show', $this->organizationA))
            ->assertNotFound();
        $this->actingAs($this->userB)
            ->get(route('stores.show', $this->storeA))
            ->assertNotFound();
    }

    public function test_store_outside_active_organization_cannot_be_discovered_by_same_user(): void
    {
        $response = $this->actingAs($this->userA)
            ->get(route('stores.show', $this->storeB))
            ->assertNotFound();

        $this->assertResponseDoesNotContain($response, ['RedTeam Store B', 'RED-B']);
    }

    public function test_store_outside_active_organization_cannot_be_deleted_by_same_user(): void
    {
        $this->actingAs($this->userA)
            ->delete(route('stores.destroy', $this->storeB))
            ->assertNotFound();

        $this->assertDatabaseHas('stores', [
            'id' => $this->storeB->id,
            'organization_id' => $this->organizationB->id,
        ]);
    }

    public function test_store_outside_active_organization_cannot_be_updated_by_same_user(): void
    {
        $this->actingAs($this->userA)
            ->patch(route('stores.update', $this->storeB), [
                'name' => 'Cross Context Store Mutation',
                'code' => 'CROSS-CONTEXT',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('stores', [
            'id' => $this->storeB->id,
            'organization_id' => $this->organizationB->id,
            'name' => 'RedTeam Store B',
            'code' => 'RED-B',
        ]);
    }

    public function test_store_membership_routes_cannot_cross_active_organization_context(): void
    {
        $target = User::factory()->create();
        $this->addOrganizationMember($this->organizationB, $target, ['stores.view']);

        $this->actingAs($this->userA)
            ->post(route('store-memberships.store', $this->storeB), ['user_id' => $target->id])
            ->assertNotFound();
        $this->assertDatabaseMissing('store_memberships', [
            'store_id' => $this->storeB->id,
            'user_id' => $target->id,
        ]);

        $membership = $this->addStoreMember($this->storeB, $target);

        $this->actingAs($this->userA)
            ->delete(route('store-memberships.destroy', $membership))
            ->assertNotFound();
        $this->assertDatabaseHas('store_memberships', ['id' => $membership->id]);
    }

    public function test_store_routes_work_after_explicit_switch_to_store_organization(): void
    {
        $this->actingAs($this->userA)
            ->post(route('context.organization', ['organizationId' => $this->organizationB->id]))
            ->assertRedirect();

        $this->actingAs($this->userA)
            ->get(route('stores.show', $this->storeB))
            ->assertOk()
            ->assertJsonPath('store.id', $this->storeB->id);
        $this->actingAs($this->userA)
            ->patch(route('stores.update', $this->storeB), [
                'name' => 'Updated Store B',
                'code' => 'RED-B',
            ])
            ->assertRedirect();
        $this->actingAs($this->userA)
            ->delete(route('stores.destroy', $this->storeB))
            ->assertRedirect();

        $this->assertDatabaseMissing('stores', ['id' => $this->storeB->id]);
    }
}
