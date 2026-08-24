<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Tests\Support\PlatformTestCase;

class MultiOrganizationWorkflowTest extends PlatformTestCase
{
    public function test_user_can_create_and_switch_between_multiple_accessible_organizations(): void
    {
        $user = User::factory()->create();
        $unrelatedUser = User::factory()->create();
        $unrelatedOrganization = $this->createOrganization($unrelatedUser, 'Organization C');

        $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'Organization A',
        ])->assertRedirect(route('platform.index'));

        $organizationA = Organization::query()->where('name', 'Organization A')->firstOrFail();

        $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'Organization B',
            'owner_id' => $unrelatedUser->id,
            'organization_id' => $unrelatedOrganization->id,
            'role_id' => 999999,
            'permission_id' => 999999,
        ])->assertRedirect(route('platform.index'));

        $organizationB = Organization::query()->where('name', 'Organization B')->firstOrFail();

        $this->assertSame($organizationA->id, $user->fresh()->active_organization_id);
        $this->assertSame($user->id, $organizationB->owner_id);
        $this->assertSame(2, $user->organizationMemberships()->where('status', 'active')->count());
        $this->assertSame('owner', $user->organizationMemberships()->whereBelongsTo($organizationA)->firstOrFail()->role->slug);
        $this->assertSame('owner', $user->organizationMemberships()->whereBelongsTo($organizationB)->firstOrFail()->role->slug);
        $this->assertTrue($user->fresh()->hasPermission($organizationA, 'stores.create'));
        $this->assertTrue($user->fresh()->hasPermission($organizationB, 'stores.create'));

        $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonCount(2, 'props.organizations')
            ->assertJsonPath('props.organizations.0.id', $organizationA->id)
            ->assertJsonPath('props.organizations.1.id', $organizationB->id);

        $this->actingAs($user)
            ->from(route('platform.index'))
            ->post(route('context.organization', ['organizationId' => $organizationB->id]))
            ->assertRedirect(route('platform.index'));

        $this->assertSame($organizationB->id, $user->fresh()->active_organization_id);
        $this->assertNull($user->fresh()->active_store_id);

        $this->actingAs($user)
            ->from(route('platform.index'))
            ->post(route('context.organization', ['organizationId' => $organizationA->id]))
            ->assertRedirect(route('platform.index'));

        $this->assertSame($organizationA->id, $user->fresh()->active_organization_id);
    }

    public function test_unrelated_forged_and_suspended_organization_switches_are_rejected(): void
    {
        $user = User::factory()->create();
        $unrelatedUser = User::factory()->create();
        $organizationA = $this->createOrganization($user, 'Organization A');
        $suspendedOrganization = $this->createOrganization($user, 'Suspended Organization');
        $unrelatedOrganization = $this->createOrganization($unrelatedUser, 'Unrelated Organization');

        $suspendedMembership = $user->organizationMemberships()
            ->whereBelongsTo($suspendedOrganization)
            ->firstOrFail();
        $suspendedMembership->status = 'suspended';
        $suspendedMembership->save();
        $this->activate($user, $organizationA);

        $this->actingAs($user)
            ->post(route('context.organization', ['organizationId' => $unrelatedOrganization->id]))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('context.organization', ['organizationId' => $suspendedOrganization->id]))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('context.organization', ['organizationId' => 999999]))
            ->assertNotFound();

        $this->assertSame($organizationA->id, $user->fresh()->active_organization_id);

        $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonCount(1, 'props.organizations')
            ->assertJsonPath('props.organizations.0.id', $organizationA->id);
    }

    public function test_multiple_stores_remain_scoped_to_the_active_organization_and_can_be_switched(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'Organization A');
        $organizationB = $this->createOrganization($owner, 'Organization B');
        $this->activate($owner, $organizationA);

        $this->actingAs($owner)->post(route('stores.store'), [
            'name' => 'Store A One',
            'code' => 'A-ONE',
            'organization_id' => $organizationB->id,
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('stores.store'), [
            'name' => 'Store A Two',
            'code' => 'A-TWO',
        ])->assertRedirect();

        $storeAOne = Store::query()->where('code', 'A-ONE')->firstOrFail();
        $storeATwo = Store::query()->where('code', 'A-TWO')->firstOrFail();

        $this->assertSame($organizationA->id, $storeAOne->organization_id);
        $this->assertSame($organizationA->id, $storeATwo->organization_id);
        $this->assertSame($storeAOne->id, $owner->fresh()->active_store_id);
        $this->assertDatabaseHas('store_memberships', ['store_id' => $storeAOne->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('store_memberships', ['store_id' => $storeATwo->id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('stores', ['organization_id' => $organizationB->id, 'code' => 'A-ONE']);

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonCount(2, 'props.stores')
            ->assertJsonPath('props.stores.0.id', $storeAOne->id)
            ->assertJsonPath('props.stores.1.id', $storeATwo->id);

        $this->actingAs($owner)
            ->from(route('platform.index'))
            ->post(route('context.store', ['storeId' => $storeATwo->id]))
            ->assertRedirect(route('platform.index'));
        $this->assertSame($storeATwo->id, $owner->fresh()->active_store_id);

        $this->actingAs($owner)
            ->from(route('platform.index'))
            ->post(route('context.organization', ['organizationId' => $organizationB->id]))
            ->assertRedirect(route('platform.index'));

        $this->assertSame($organizationB->id, $owner->fresh()->active_organization_id);
        $this->assertNull($owner->fresh()->active_store_id);

        $this->actingAs($owner)->post(route('stores.store'), [
            'name' => 'Store B',
            'code' => 'B-MAIN',
        ])->assertRedirect();

        $storeB = Store::query()->where('code', 'B-MAIN')->firstOrFail();

        $this->assertSame($organizationB->id, $storeB->organization_id);
        $this->assertSame($storeB->id, $owner->fresh()->active_store_id);

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonCount(1, 'props.stores')
            ->assertJsonPath('props.stores.0.id', $storeB->id);

        $this->actingAs($owner)
            ->from(route('platform.index'))
            ->post(route('context.organization', ['organizationId' => $organizationA->id]))
            ->assertRedirect(route('platform.index'));

        $this->assertSame($organizationA->id, $owner->fresh()->active_organization_id);
        $this->assertNull($owner->fresh()->active_store_id);

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonCount(2, 'props.stores')
            ->assertJsonPath('props.stores.0.id', $storeAOne->id)
            ->assertJsonPath('props.stores.1.id', $storeATwo->id);

        $this->actingAs($owner)
            ->post(route('context.store', ['storeId' => $storeB->id]))
            ->assertForbidden();
        $this->assertNull($owner->fresh()->active_store_id);
    }

    public function test_unrelated_user_cannot_switch_to_a_store_from_another_organization(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $storeA = $this->createStore($organizationA, $ownerA, 'Store A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $this->activate($ownerB, $organizationB);

        $this->actingAs($ownerB)
            ->post(route('context.store', ['storeId' => $storeA->id]))
            ->assertForbidden();

        $this->assertSame($organizationB->id, $ownerB->fresh()->active_organization_id);
        $this->assertNull($ownerB->fresh()->active_store_id);
    }
}
