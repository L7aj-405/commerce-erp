<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Tests\Support\PlatformTestCase;

class TenantContextTest extends PlatformTestCase
{
    public function test_active_organization_must_belong_to_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');
        $userA->active_organization_id = $organizationB->id;
        $userA->save();

        $this->actingAs($userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk();

        $this->assertNull($userA->fresh()->active_organization_id);
    }

    public function test_active_store_must_belong_to_active_organization_and_be_accessible(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $organizationA = $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');
        $storeB = $this->createStore($organizationB, $userB, 'Store B');
        $this->activate($userA, $organizationA);
        $userA->active_store_id = $storeB->id;
        $userA->save();

        $this->actingAs($userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk();

        $this->assertSame($organizationA->id, $userA->fresh()->active_organization_id);
        $this->assertNull($userA->fresh()->active_store_id);
    }

    public function test_unauthorized_organization_and_store_switches_fail(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $organizationA = $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');
        $storeB = $this->createStore($organizationB, $userB, 'Store B');
        $this->activate($userA, $organizationA);

        $this->actingAs($userA)->post(route('context.organization', ['organizationId' => $organizationB->id]))->assertForbidden();
        $this->actingAs($userA)->post(route('context.store', ['storeId' => $storeB->id]))->assertForbidden();

        $this->assertSame($organizationA->id, $userA->fresh()->active_organization_id);
        $this->assertNull($userA->fresh()->active_store_id);
    }

    public function test_suspended_membership_invalidates_active_context(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $membership = $this->addOrganizationMember($organization, $member, ['organizations.view']);
        $this->activate($member, $organization);
        $membership->status = 'suspended';
        $membership->save();

        $this->actingAs($member)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk();

        $this->assertNull($member->fresh()->active_organization_id);
    }

    public function test_inactive_store_cannot_remain_active(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $store->status = 'inactive';
        $store->save();

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk();

        $this->assertNull($owner->fresh()->active_store_id);
    }
}
