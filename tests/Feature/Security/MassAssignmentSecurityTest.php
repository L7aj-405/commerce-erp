<?php

namespace Tests\Feature\Security;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\Support\PlatformTestCase;

class MassAssignmentSecurityTest extends PlatformTestCase
{
    public function test_forged_owner_and_status_are_ignored_when_creating_organization(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($attacker)->post(route('organizations.store'), [
            'name' => 'Attacker Organization',
            'owner_id' => $victim->id,
            'status' => 'suspended',
            'active_organization_id' => 999999,
        ])->assertRedirect(route('platform.index'));

        $organization = Organization::query()->where('name', 'Attacker Organization')->firstOrFail();
        $this->assertSame($attacker->id, $organization->owner_id);
        $this->assertSame('active', $organization->status);
    }

    public function test_organization_protects_owner_and_status_fields(): void
    {
        $organization = new Organization;
        $organization->fill([
            'name' => 'Safe',
            'owner_id' => 123,
            'status' => 'suspended',
            'settings' => ['timezone' => 'UTC'],
        ]);

        $this->assertSame('Safe', $organization->name);
        $this->assertNull($organization->owner_id);
        $this->assertNull($organization->status);
    }

    public function test_store_protects_organization_and_status_fields(): void
    {
        $store = new Store;
        $store->fill([
            'name' => 'Safe',
            'code' => 'SAFE',
            'organization_id' => 123,
            'status' => 'inactive',
        ]);

        $this->assertNull($store->organization_id);
        $this->assertNull($store->status);
    }

    public function test_role_protects_organization_and_system_fields(): void
    {
        $role = new Role;
        $role->fill([
            'name' => 'Forged Owner',
            'slug' => 'owner',
            'organization_id' => 123,
            'is_system' => true,
        ]);

        $this->assertNull($role->organization_id);
        $this->assertNull($role->is_system);
    }

    public function test_membership_models_reject_all_mass_assignment(): void
    {
        foreach ([new OrganizationMembership, new StoreMembership] as $membership) {
            try {
                $membership->fill([
                    'organization_id' => 1,
                    'store_id' => 1,
                    'user_id' => 1,
                    'role_id' => 1,
                    'status' => 'active',
                ]);

                $this->fail('Mass assignment should have been rejected.');
            } catch (MassAssignmentException) {
                $this->assertSame([], $membership->getAttributes());
            }
        }
    }

    public function test_user_active_context_is_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill([
            'name' => 'User',
            'email' => 'user@example.com',
            'password' => 'password',
            'active_organization_id' => 123,
            'active_store_id' => 456,
        ]);

        $this->assertNull($user->active_organization_id);
        $this->assertNull($user->active_store_id);
    }

    public function test_forged_fields_are_ignored_by_store_update_endpoint(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $store = $this->createStore($organizationA, $ownerA);

        $this->actingAs($ownerA)->patch(route('stores.update', $store), [
            'name' => 'Updated Store',
            'code' => 'UPDATED',
            'organization_id' => $organizationB->id,
            'store_id' => 999999,
            'owner_id' => $ownerB->id,
            'status' => 'inactive',
        ])->assertRedirect();

        $store->refresh();
        $this->assertSame($organizationA->id, $store->organization_id);
        $this->assertSame('active', $store->status);
    }
}
