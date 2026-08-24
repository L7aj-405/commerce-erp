<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\PlatformTestCase;

class OrganizationMembershipTest extends PlatformTestCase
{
    public function test_valid_membership_can_be_created_and_is_tenant_scoped(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $this->actingAs($owner)->post(route('organization-memberships.store', $organization), [
            'user_id' => $member->id,
            'role_id' => $role->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_membership_is_rejected(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $this->addOrganizationMember($organization, $member, ['organizations.view']);

        $this->actingAs($owner)->postJson(route('organization-memberships.store', $organization), [
            'user_id' => $member->id,
            'role_id' => $role->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_suspended_membership_denies_organization_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $member, ['organizations.view'], 'suspended');

        $this->actingAs($member)->get(route('organizations.show', $organization))->assertNotFound();
    }

    public function test_unauthorized_user_cannot_add_or_remove_members(): void
    {
        $owner = User::factory()->create();
        $salesUser = User::factory()->create();
        $target = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $salesMembership = $this->addOrganizationMember(
            $organization,
            $salesUser,
            ['organizations.view', 'members.view'],
            roleName: 'Sales Employee',
        );
        $targetMembership = $this->addOrganizationMember($organization, $target, ['organizations.view']);

        $this->actingAs($salesUser)->post(route('organization-memberships.store', $organization), [
            'user_id' => User::factory()->create()->id,
            'role_id' => $salesMembership->role_id,
        ])->assertForbidden();

        $this->actingAs($salesUser)
            ->delete(route('organization-memberships.destroy', $targetMembership))
            ->assertForbidden();
    }

    public function test_user_cannot_add_themselves_to_another_organization(): void
    {
        $userA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $roleB = $organizationB->roles()->where('slug', 'admin')->firstOrFail();

        $this->actingAs($userA)->post(route('organization-memberships.store', $organizationB), [
            'user_id' => $userA->id,
            'role_id' => $roleB->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organizationB->id,
            'user_id' => $userA->id,
        ]);
    }
}
