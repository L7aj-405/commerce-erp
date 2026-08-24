<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Tests\Support\PlatformTestCase;

class PlatformOnboardingTest extends PlatformTestCase
{
    public function test_user_without_an_organization_can_create_their_first_organization_with_owner_access(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->assertSame(0, $user->organizationMemberships()->count());

        $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'AV Professional',
            'organization_id' => 999999,
            'owner_id' => $otherUser->id,
            'role_id' => 999999,
            'permission_ids' => [],
            'status' => 'inactive',
        ])->assertRedirect(route('platform.index'));

        $organization = Organization::query()->where('name', 'AV Professional')->firstOrFail();
        $membership = $user->organizationMemberships()->with('role')->whereBelongsTo($organization)->firstOrFail();

        $this->assertSame($user->id, $organization->owner_id);
        $this->assertSame('active', $organization->status);
        $this->assertSame('active', $membership->status);
        $this->assertSame('owner', $membership->role->slug);
        $this->assertTrue($membership->role->is_system);
        $this->assertSame($organization->id, $user->fresh()->active_organization_id);
        $this->assertNull($user->fresh()->active_store_id);
        $this->assertTrue($user->fresh()->hasPermission($organization, 'organizations.view'));
        $this->assertTrue($user->fresh()->hasPermission($organization, 'roles.assign-permissions'));
        $this->assertTrue($user->fresh()->hasPermission($organization, 'stores.create'));
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_id' => $user->id,
            'event' => 'organization.created',
        ]);
    }

    public function test_created_organization_is_exposed_as_accessible_and_active_on_platform(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'AV Professional',
        ])->assertRedirect(route('platform.index'));

        $organization = Organization::query()->where('name', 'AV Professional')->firstOrFail();

        $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonPath('component', 'Platform/Index')
            ->assertJsonCount(1, 'props.organizations')
            ->assertJsonPath('props.organizations.0.id', $organization->id)
            ->assertJsonPath('props.tenant.organization.id', $organization->id)
            ->assertJsonPath('props.tenant.store', null);
    }

    public function test_owner_can_create_first_store_only_in_the_active_organization_and_receives_access(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Organization A');
        $otherOrganization = $this->createOrganization($otherOwner, 'Organization B');

        $this->actingAs($owner)
            ->from(route('platform.index'))
            ->post(route('stores.store'), [
                'name' => 'Main Store',
                'code' => 'MAIN',
                'organization_id' => $otherOrganization->id,
                'user_id' => $otherOwner->id,
                'status' => 'inactive',
            ])
            ->assertRedirect(route('platform.index'));

        $store = Store::query()->where('code', 'MAIN')->firstOrFail();

        $this->assertSame($organization->id, $store->organization_id);
        $this->assertSame('active', $store->status);
        $this->assertSame($store->id, $owner->fresh()->active_store_id);
        $this->assertDatabaseHas('store_memberships', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $owner->id,
        ]);
        $this->assertDatabaseMissing('stores', [
            'organization_id' => $otherOrganization->id,
            'code' => 'MAIN',
        ]);
        $this->assertDatabaseMissing('store_memberships', [
            'store_id' => $store->id,
            'user_id' => $otherOwner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'actor_id' => $owner->id,
            'event' => 'store.created',
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonCount(1, 'props.stores')
            ->assertJsonPath('props.stores.0.id', $store->id)
            ->assertJsonPath('props.tenant.store.id', $store->id);
    }

    public function test_store_creation_cannot_mutate_an_organization_outside_the_users_tenant_context(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');

        $this->actingAs($ownerA)
            ->post(route('context.organization', ['organizationId' => $organizationB->id]))
            ->assertForbidden();

        $this->actingAs($ownerA)->post(route('stores.store'), [
            'name' => 'Forged Store',
            'code' => 'FORGED',
            'organization_id' => $organizationB->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('stores', [
            'organization_id' => $organizationA->id,
            'name' => 'Forged Store',
            'code' => 'FORGED',
        ]);
        $this->assertDatabaseMissing('stores', [
            'organization_id' => $organizationB->id,
            'code' => 'FORGED',
        ]);
        $this->assertSame($organizationA->id, $ownerA->fresh()->active_organization_id);
    }
}
