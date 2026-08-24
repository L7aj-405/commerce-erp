<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\Role;
use App\Models\User;

class PrivilegeEscalationAttackTest extends TenantRedTeamTestCase
{
    public function test_member_cannot_assign_themselves_admin_or_owner_with_forged_payloads(): void
    {
        $attacker = User::factory()->create();
        $membership = $this->addOrganizationMember(
            $this->organizationA,
            $attacker,
            ['organizations.view', 'members.update'],
            roleName: 'Self Promotion Attacker',
        );
        $this->activate($attacker, $this->organizationA);
        $originalRoleId = $membership->role_id;

        foreach (['admin', 'owner'] as $slug) {
            $privilegedRole = $this->organizationA->roles()->where('slug', $slug)->firstOrFail();

            $this->actingAs($attacker)
                ->patch(route('organization-memberships.update', $membership), [
                    'role_id' => $privilegedRole->id,
                    'organization_id' => $this->organizationB->id,
                    'user_id' => $attacker->id,
                    'membership_id' => $membership->id,
                    'owner_id' => $attacker->id,
                ])
                ->assertForbidden();
        }

        $this->assertSame($originalRoleId, $membership->fresh()->role_id);
    }

    public function test_foreign_role_id_cannot_be_assigned_to_membership(): void
    {
        $target = User::factory()->create();
        $membership = $this->addOrganizationMember($this->organizationA, $target, ['organizations.view']);
        $foreignAdminRole = $this->organizationB->roles()->where('slug', 'admin')->firstOrFail();
        $originalRoleId = $membership->role_id;

        $this->actingAs($this->userA)
            ->patchJson(route('organization-memberships.update', $membership), [
                'role_id' => $foreignAdminRole->id,
                'organization_id' => $this->organizationB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');

        $this->assertSame($originalRoleId, $membership->fresh()->role_id);
    }

    public function test_unrelated_user_cannot_mutate_memberships_roles_or_permissions_by_exact_id(): void
    {
        $target = User::factory()->create();
        $membershipA = $this->addOrganizationMember($this->organizationA, $target, ['organizations.view']);
        $roleA = $this->createRole($this->organizationA, [], 'Protected Role A');
        $permission = $this->permission('organizations.delete');

        $this->actingAs($this->userB)
            ->patch(route('organization-memberships.update', $membershipA), [
                'role_id' => $roleA->id,
            ])
            ->assertNotFound();
        $this->actingAs($this->userB)
            ->put(route('roles.permissions.update', $roleA), [
                'permission_ids' => [$permission->id],
            ])
            ->assertNotFound();

        $this->assertNotSame($roleA->id, $membershipA->fresh()->role_id);
        $this->assertFalse($roleA->permissions()->whereKey($permission->id)->exists());
    }

    public function test_role_creation_uses_active_context_and_ignores_forged_organization_id(): void
    {
        $this->actingAs($this->userA)->post(route('roles.store'), [
            'name' => 'Context Scoped Role',
            'slug' => 'context-scoped-role',
            'organization_id' => $this->organizationB->id,
            'owner_id' => $this->userB->id,
            'permission_id' => $this->permission('organizations.delete')->id,
        ])->assertRedirect();

        $role = Role::query()->where('slug', 'context-scoped-role')->firstOrFail();
        $this->assertSame($this->organizationA->id, $role->organization_id);
        $this->assertFalse($role->is_system);
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_permission_admin_cannot_grant_permissions_they_do_not_possess(): void
    {
        $attacker = User::factory()->create();
        $this->addOrganizationMember(
            $this->organizationA,
            $attacker,
            ['organizations.view', 'roles.assign-permissions'],
            roleName: 'Limited Permission Admin',
        );
        $targetRole = $this->createRole($this->organizationA, [], 'Escalation Target');
        $forbiddenPermission = $this->permission('organizations.delete');
        $this->activate($attacker, $this->organizationA);

        $this->actingAs($attacker)
            ->put(route('roles.permissions.update', $targetRole), [
                'permission_ids' => [$forbiddenPermission->id],
                'permission_id' => $forbiddenPermission->id,
                'organization_id' => $this->organizationB->id,
            ])
            ->assertForbidden();

        $this->assertFalse($targetRole->permissions()->whereKey($forbiddenPermission->id)->exists());
    }
}
