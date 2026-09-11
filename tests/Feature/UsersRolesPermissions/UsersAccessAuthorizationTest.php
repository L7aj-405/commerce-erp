<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class UsersAccessAuthorizationTest extends PlatformTestCase
{
    public function test_members_view_is_required_to_open_the_page(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $outsider, ['customers.view']);

        $this->actingAs($outsider)->get(route('users-access.index', $organization))->assertForbidden();
    }

    public function test_owner_sees_the_full_payload(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->get(route('users-access.index', $organization))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('memberships', 1)
                ->has('roles', 3) // owner, admin, sales-employee
                ->where('can.viewRoles', true)
                ->where('can.createRole', true)
                ->where('can.manageMembers', true));
    }

    public function test_roles_and_invitations_are_hidden_from_a_member_without_those_permissions(): void
    {
        $owner = User::factory()->create();
        $limited = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $limited, ['members.view']);

        $this->actingAs($limited)->get(route('users-access.index', $organization))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewRoles', false)
                ->where('roles', [])
                ->where('can.manageMembers', false)
                ->where('invitations', []));
    }

    public function test_a_member_of_another_organization_gets_a_404_not_a_403(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);

        // Organization-boundary IDOR check: a real, existing organization id
        // belonging to another tenant must 404, not reveal it exists via 403.
        $this->actingAs($ownerA)->get(route('users-access.index', $organizationB))->assertNotFound();
    }

    public function test_permission_matrix_data_reflects_the_actual_permission_catalogue(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->get(route('users-access.index', $organization))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('permissionGroups.finance', 3)
                ->has('presets', 3));
    }
}
