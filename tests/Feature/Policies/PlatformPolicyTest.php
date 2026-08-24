<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;
use Illuminate\Support\Facades\Gate;
use Tests\Support\PlatformTestCase;

class PlatformPolicyTest extends PlatformTestCase
{
    public function test_organization_policy_authorizes_and_denies_all_relevant_operations(): void
    {
        $owner = User::factory()->create();
        $authorized = User::factory()->create();
        $unauthorized = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $authorized, [
            'organizations.view',
            'organizations.update',
            'organizations.delete',
            'settings.update',
        ]);
        $this->addOrganizationMember($organization, $unauthorized, []);

        $this->assertTrue(Gate::forUser($authorized)->allows('viewAny', Organization::class));
        $this->assertTrue(Gate::forUser($authorized)->allows('view', $organization));
        $this->assertTrue(Gate::forUser($authorized)->allows('create', Organization::class));
        $this->assertTrue(Gate::forUser($authorized)->allows('update', $organization));
        $this->assertTrue(Gate::forUser($authorized)->allows('delete', $organization));

        $this->assertFalse(Gate::forUser($unauthorized)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('delete', $organization));
    }

    public function test_store_policy_authorizes_and_denies_all_relevant_operations(): void
    {
        $owner = User::factory()->create();
        $authorized = User::factory()->create();
        $unauthorized = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $authorized, [
            'stores.view',
            'stores.create',
            'stores.update',
            'stores.delete',
        ]);
        $this->addOrganizationMember($organization, $unauthorized, []);
        $store = $this->createStore($organization, $owner);
        $this->addStoreMember($store, $authorized);
        $this->activate($authorized, $organization, $store);
        $this->activate($unauthorized, $organization);
        app(ActiveTenantContext::class)->resolve($authorized->fresh());

        $this->assertTrue(Gate::forUser($authorized)->allows('viewAny', [Store::class, $organization]));
        $this->assertTrue(Gate::forUser($authorized)->allows('view', $store));
        $this->assertTrue(Gate::forUser($authorized)->allows('create', [Store::class, $organization]));
        $this->assertTrue(Gate::forUser($authorized)->allows('update', $store));
        $this->assertTrue(Gate::forUser($authorized)->allows('delete', $store));

        $this->assertFalse(Gate::forUser($unauthorized)->allows('view', $store));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('create', [Store::class, $organization]));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('update', $store));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('delete', $store));
    }

    public function test_membership_and_role_policies_are_explicitly_permission_based(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $unauthorized = User::factory()->create();
        $target = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $manager, [
            'members.view',
            'members.create',
            'members.update',
            'members.delete',
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign-permissions',
        ]);
        $this->addOrganizationMember($organization, $unauthorized, []);
        $targetMembership = $this->addOrganizationMember($organization, $target, []);
        $role = $this->createRole($organization, [], 'Editable Role');

        $this->assertTrue(Gate::forUser($manager)->allows('viewAny', [OrganizationMembership::class, $organization]));
        $this->assertTrue(Gate::forUser($manager)->allows('create', [OrganizationMembership::class, $organization]));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $targetMembership));
        $this->assertTrue(Gate::forUser($manager)->allows('delete', $targetMembership));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $role));
        $this->assertTrue(Gate::forUser($manager)->allows('assignPermissions', $role));
        $this->assertTrue(Gate::forUser($manager)->allows('delete', $role));

        $this->assertFalse(Gate::forUser($unauthorized)->allows('create', [OrganizationMembership::class, $organization]));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('update', $targetMembership));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('update', $role));
        $this->assertFalse(Gate::forUser($unauthorized)->allows('delete', $role));
    }
}
