<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\PlatformTestCase;

class RbacAuthorizationTest extends PlatformTestCase
{
    public function test_authorized_permission_succeeds_and_missing_permission_fails(): void
    {
        $owner = User::factory()->create();
        $authorized = User::factory()->create();
        $unauthorized = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $authorized, ['organizations.view', 'organizations.update']);
        $this->addOrganizationMember($organization, $unauthorized, ['organizations.view']);

        $this->actingAs($authorized)->patch(route('organizations.update', $organization), ['name' => 'Authorized'])->assertRedirect();
        $this->actingAs($unauthorized)->patch(route('organizations.update', $organization), ['name' => 'Denied'])->assertForbidden();

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => 'Authorized']);
    }

    public function test_permissions_are_scoped_to_organization(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $member = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $this->addOrganizationMember($organizationA, $member, ['organizations.view', 'organizations.update']);
        $this->addOrganizationMember($organizationB, $member, ['organizations.view']);

        $this->actingAs($member)->patch(route('organizations.update', $organizationA), ['name' => 'Updated A'])->assertRedirect();
        $this->actingAs($member)->patch(route('organizations.update', $organizationB), ['name' => 'Forged B'])->assertForbidden();

        $this->assertDatabaseHas('organizations', ['id' => $organizationB->id, 'name' => 'Organization B']);
    }

    public function test_removing_permission_removes_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $membership = $this->addOrganizationMember($organization, $member, ['organizations.view', 'organizations.update']);

        $this->assertTrue($member->hasPermission($organization, 'organizations.update'));
        $membership->role->permissions()->detach($this->permission('organizations.update'));

        $this->actingAs($member)->patch(route('organizations.update', $organization), ['name' => 'Denied'])->assertForbidden();
    }

    public function test_sales_employee_cannot_manage_users_roles_or_settings(): void
    {
        $owner = User::factory()->create();
        $sales = User::factory()->create();
        $target = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $salesRole = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $salesMembership = $this->addOrganizationMember(
            $organization,
            $sales,
            ['organizations.view', 'stores.view'],
            roleName: 'Sales Employee Custom',
        );
        $this->activate($sales, $organization);

        $this->actingAs($sales)->post(route('organization-memberships.store', $organization), [
            'user_id' => $target->id,
            'role_id' => $salesRole->id,
        ])->assertForbidden();
        $this->actingAs($sales)->post(route('roles.store'), ['name' => 'Manager'])->assertForbidden();
        $this->actingAs($sales)->patch(route('organizations.settings.update', $organization), [
            'settings' => ['timezone' => 'UTC'],
        ])->assertForbidden();

        $this->assertDatabaseHas('organization_memberships', ['id' => $salesMembership->id]);
        $this->assertDatabaseMissing('roles', [
            'organization_id' => $organization->id,
            'name' => 'Manager',
        ]);
    }

    public function test_admin_only_performs_explicitly_granted_actions(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $admin, ['organizations.view', 'stores.view'], roleName: 'Limited Admin');
        $store = $this->createStore($organization, $owner);
        $this->addStoreMember($store, $admin);
        $this->activate($admin, $organization, $store);

        $this->actingAs($admin)->get(route('stores.show', $store))->assertOk();
        $this->actingAs($admin)->post(route('stores.store'), ['name' => 'Denied', 'code' => 'DENIED'])->assertForbidden();
        $this->actingAs($admin)->post(route('roles.store'), ['name' => 'Denied'])->assertForbidden();
    }
}
