<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Tests\Support\PlatformTestCase;

class StoreIsolationTest extends PlatformTestCase
{
    public function test_user_cannot_view_or_update_another_organizations_store(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $organizationA = $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');
        $storeB = $this->createStore($organizationB, $userB, 'Store B');
        $this->activate($userA, $organizationA);

        $this->actingAs($userA)->get(route('stores.show', $storeB))->assertNotFound();
        $this->actingAs($userA)->patch(route('stores.update', $storeB), [
            'name' => 'Forged',
            'code' => 'FORGED',
        ])->assertNotFound();

        $this->assertDatabaseHas('stores', ['id' => $storeB->id, 'name' => 'Store B']);
    }

    public function test_manually_changing_store_id_does_not_grant_access(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $organizationA = $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');
        $storeB = $this->createStore($organizationB, $userB, 'Store B');
        $this->activate($userA, $organizationA);

        $this->actingAs($userA)
            ->post(route('context.store', ['storeId' => $storeB->id]))
            ->assertForbidden();

        $this->assertNull($userA->fresh()->active_store_id);
    }

    public function test_store_membership_cannot_be_created_for_user_from_another_organization(): void
    {
        $ownerA = User::factory()->create();
        $userB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $this->createOrganization($userB, 'Organization B');
        $storeA = $this->createStore($organizationA, $ownerA, 'Store A');

        $this->actingAs($ownerA)
            ->postJson(route('store-memberships.store', $storeA), ['user_id' => $userB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseMissing('store_memberships', [
            'store_id' => $storeA->id,
            'user_id' => $userB->id,
        ]);
    }

    public function test_cross_tenant_store_membership_route_binding_is_rejected(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $target = User::factory()->create();
        $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $this->addOrganizationMember($organizationB, $target, ['stores.view']);
        $storeB = $this->createStore($organizationB, $ownerB, 'Store B');
        $membershipB = $this->addStoreMember($storeB, $target);

        $this->actingAs($ownerA)
            ->delete(route('store-memberships.destroy', $membershipB))
            ->assertNotFound();

        $this->assertDatabaseHas('store_memberships', ['id' => $membershipB->id]);
    }
}
