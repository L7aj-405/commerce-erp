<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\PlatformTestCase;

class StoreMembershipTest extends PlatformTestCase
{
    public function test_valid_store_membership_can_be_created(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $member, ['stores.view']);
        $store = $this->createStore($organization, $owner);

        $this->actingAs($owner)->post(route('store-memberships.store', $store), [
            'user_id' => $member->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('store_memberships', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_duplicate_store_membership_is_rejected(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $member, ['stores.view']);
        $store = $this->createStore($organization, $owner);
        $this->addStoreMember($store, $member);

        $this->actingAs($owner)->postJson(route('store-memberships.store', $store), [
            'user_id' => $member->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_store_must_belong_to_the_users_active_organization(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Organization A');
        $otherOrganization = $this->createOrganization($otherOwner, 'Organization B');
        $otherStore = $this->createStore($otherOrganization, $otherOwner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)
            ->post(route('context.store', ['storeId' => $otherStore->id]))
            ->assertForbidden();
    }

    public function test_user_cannot_access_or_switch_to_unauthorized_store(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $member, ['stores.view']);
        $store = $this->createStore($organization, $owner);
        $this->activate($member, $organization);

        $this->actingAs($member)->get(route('stores.show', $store))->assertNotFound();
        $this->actingAs($member)->post(route('context.store', ['storeId' => $store->id]))->assertForbidden();
    }

    public function test_store_membership_cannot_cross_organizations(): void
    {
        $ownerA = User::factory()->create();
        $memberB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $this->createOrganization($memberB, 'Organization B');
        $storeA = $this->createStore($organizationA, $ownerA);

        $this->actingAs($ownerA)->postJson(route('store-memberships.store', $storeA), [
            'user_id' => $memberB->id,
            'organization_id' => $organizationA->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('store_memberships', [
            'store_id' => $storeA->id,
            'user_id' => $memberB->id,
        ]);
    }
}
