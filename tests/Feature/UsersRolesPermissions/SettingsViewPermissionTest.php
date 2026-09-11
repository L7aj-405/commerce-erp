<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class SettingsViewPermissionTest extends PlatformTestCase
{
    public function test_settings_view_grants_read_only_access_to_document_profile(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['settings.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->get(route('document-profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));

        $this->actingAs($viewer)->put(route('document-profile.update'), [
            'legal_name' => 'Forged Co',
        ])->assertForbidden();
    }

    public function test_settings_update_still_grants_both_read_and_write(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $editor, ['settings.update']);
        $this->activate($editor, $organization);

        $this->actingAs($editor)->get(route('document-profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canUpdate', true));

        $this->actingAs($editor)->put(route('document-profile.update'), [
            'legal_name' => 'Updated Co',
        ])->assertRedirect();

        $organization->refresh();
        $this->assertSame('Updated Co', data_get($organization->settings, 'document_profile.legal_name'));
    }

    public function test_member_without_settings_permission_cannot_view_or_update(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $outsider, ['customers.view']);
        $this->activate($outsider, $organization);

        $this->actingAs($outsider)->get(route('document-profile.edit'))->assertForbidden();
        $this->actingAs($outsider)->put(route('document-profile.update'), ['legal_name' => 'Nope'])->assertForbidden();
    }

    public function test_settings_view_also_applies_to_quotation_settings(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['settings.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->get(route('quotation-settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));

        $this->actingAs($viewer)->put(route('quotation-settings.update'), [
            'default_validity_days' => 30,
        ])->assertForbidden();
    }

    public function test_finance_preset_role_does_not_receive_settings_view(): void
    {
        // Business decision 9: Finance does not get organization/system
        // settings access in V1.
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->actingAs($owner)->post(route('roles.store'), ['name' => 'Finance Role', 'preset_slug' => 'finance'])->assertRedirect();

        $role = $organization->roles()->where('name', 'Finance Role')->firstOrFail();
        $financeUser = User::factory()->create();
        $this->attachRole($organization, $financeUser, $role);
        $this->activate($financeUser, $organization);

        $this->actingAs($financeUser)->get(route('document-profile.edit'))->assertForbidden();
    }

    private function attachRole(Organization $organization, User $user, Role $role): void
    {
        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->getKey();
        $membership->user_id = $user->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = 'active';
        $membership->save();
    }
}
