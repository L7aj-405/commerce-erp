<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Tests\Support\PlatformTestCase;

class RoleEscalationTest extends PlatformTestCase
{
    public function test_actor_cannot_create_a_role_granting_a_permission_they_do_not_hold(): void
    {
        $owner = User::factory()->create();
        $limited = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $limited, ['roles.create', 'roles.assign-permissions', 'customers.view']);
        $this->activate($limited, $organization);

        $forbidden = $this->permission('finance.export');

        $this->actingAs($limited)->post(route('roles.store'), [
            'name' => 'Escalated Role',
            'permission_ids' => [$forbidden->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['organization_id' => $organization->getKey(), 'name' => 'Escalated Role']);
    }

    public function test_actor_without_roles_create_cannot_create_any_role(): void
    {
        $owner = User::factory()->create();
        $limited = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $limited, ['roles.assign-permissions']);
        $this->activate($limited, $organization);

        $this->actingAs($limited)->post(route('roles.store'), ['name' => 'Nope'])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['organization_id' => $organization->getKey(), 'name' => 'Nope']);
    }

    public function test_actor_can_create_a_role_limited_to_permissions_they_hold(): void
    {
        $owner = User::factory()->create();
        $limited = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $limited, ['roles.create', 'roles.assign-permissions', 'customers.view']);
        $this->activate($limited, $organization);

        $allowed = $this->permission('customers.view');

        $this->actingAs($limited)->post(route('roles.store'), [
            'name' => 'Safe Role',
            'permission_ids' => [$allowed->id],
        ])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'Safe Role')->firstOrFail();
        $this->assertSame(['customers.view'], $role->permissions()->pluck('key')->all());
    }

    public function test_system_roles_cannot_be_renamed_or_have_permissions_reassigned(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $adminRole = Role::query()->where('organization_id', $organization->getKey())->where('slug', 'admin')->firstOrFail();
        $originalName = $adminRole->name;
        $originalPermissions = $adminRole->permissions()->pluck('key')->sort()->values()->all();

        $this->actingAs($owner)->patch(route('roles.update', $adminRole), ['name' => 'Hacked Admin', 'slug' => 'admin'])
            ->assertForbidden();

        $somePermission = $this->permission('customers.view');
        $this->actingAs($owner)->put(route('roles.permissions.update', $adminRole), ['permission_ids' => [$somePermission->id]])
            ->assertForbidden();

        $adminRole->refresh();
        $this->assertSame($originalName, $adminRole->name);
        $this->assertSame($originalPermissions, $adminRole->permissions()->pluck('key')->sort()->values()->all());
    }

    public function test_role_with_active_members_cannot_be_deleted_but_an_empty_custom_role_can(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('roles.store'), ['name' => 'Assignable'])->assertRedirect();
        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'Assignable')->firstOrFail();

        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->getKey();
        $membership->user_id = $target->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = 'active';
        $membership->save();

        $this->actingAs($owner)->delete(route('roles.destroy', $role))->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $role->id]);

        $membership->delete();

        $this->actingAs($owner)->delete(route('roles.destroy', $role))->assertRedirect();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_owner_role_can_never_be_assigned_to_a_member(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $ownerRole = Role::query()->where('organization_id', $organization->getKey())->where('slug', 'owner')->firstOrFail();

        $this->actingAs($owner)->postJson(route('organization-memberships.store', $organization), [
            'email' => $target->email,
            'role_id' => $ownerRole->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organization->getKey(),
            'user_id' => $target->getKey(),
        ]);
    }
}
