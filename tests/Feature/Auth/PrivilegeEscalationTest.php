<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\PlatformTestCase;

class PrivilegeEscalationTest extends PlatformTestCase
{
    public function test_normal_user_cannot_assign_themselves_admin_or_owner(): void
    {
        $owner = User::factory()->create();
        $normalUser = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $membership = $this->addOrganizationMember($organization, $normalUser, ['organizations.view']);
        $adminRole = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $ownerRole = $organization->roles()->where('slug', 'owner')->firstOrFail();

        $this->actingAs($normalUser)
            ->patch(route('organization-memberships.update', $membership), ['role_id' => $adminRole->id])
            ->assertForbidden();
        $this->actingAs($normalUser)
            ->patch(route('organization-memberships.update', $membership), ['role_id' => $ownerRole->id])
            ->assertForbidden();

        $this->assertSame($membership->role_id, $membership->fresh()->role_id);
    }

    public function test_user_with_member_update_permission_still_cannot_modify_their_own_role(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $membership = $this->addOrganizationMember(
            $organization,
            $member,
            ['organizations.view', 'members.update'],
        );
        $adminRole = $organization->roles()->where('slug', 'admin')->firstOrFail();

        $this->actingAs($member)
            ->patch(route('organization-memberships.update', $membership), [
                'role_id' => $adminRole->id,
                'membership_id' => $membership->id,
                'user_id' => $member->id,
            ])
            ->assertForbidden();

        $this->assertSame($membership->role_id, $membership->fresh()->role_id);
    }

    public function test_user_cannot_assign_permissions_they_do_not_hold(): void
    {
        $owner = User::factory()->create();
        $limitedAdmin = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember(
            $organization,
            $limitedAdmin,
            ['organizations.view', 'roles.assign-permissions'],
        );
        $targetRole = $this->createRole($organization, [], 'Target Role');
        $forbiddenPermission = $this->permission('organizations.delete');

        $this->actingAs($limitedAdmin)
            ->put(route('roles.permissions.update', $targetRole), [
                'permission_ids' => [$forbiddenPermission->id],
                'permission_id' => $forbiddenPermission->id,
            ])
            ->assertForbidden();

        $this->assertFalse($targetRole->permissions()->whereKey($forbiddenPermission->id)->exists());
    }

    public function test_normal_user_cannot_promote_another_user(): void
    {
        $owner = User::factory()->create();
        $normalUser = User::factory()->create();
        $target = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $normalUser, ['organizations.view']);
        $targetMembership = $this->addOrganizationMember($organization, $target, ['organizations.view']);
        $adminRole = $organization->roles()->where('slug', 'admin')->firstOrFail();

        $this->actingAs($normalUser)
            ->patch(route('organization-memberships.update', $targetMembership), ['role_id' => $adminRole->id])
            ->assertForbidden();

        $this->assertSame($targetMembership->role_id, $targetMembership->fresh()->role_id);
    }

    public function test_tenant_ownership_fields_cannot_be_changed_through_forged_input(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->patch(route('organizations.update', $organization), [
            'name' => 'Renamed',
            'owner_id' => $attacker->id,
            'organization_id' => 999999,
            'status' => 'suspended',
        ])->assertRedirect();

        $organization->refresh();
        $this->assertSame($owner->id, $organization->owner_id);
        $this->assertSame('active', $organization->status);
    }
}
