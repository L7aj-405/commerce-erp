<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\OrganizationMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

/**
 * The Inertia-shared `tenant.permissions` array (HandleInertiaRequests) is what
 * the frontend nav (ApplicationShell) uses to show/hide "Utilisateurs & accès"
 * and the settings.view/settings.update-gated links. It must reflect the new
 * permission keys exactly — the frontend check is convenience only, but it has
 * to see the real permission set to convey it correctly.
 */
class SharedPermissionsPayloadTest extends PlatformTestCase
{
    public function test_shared_tenant_permissions_include_the_new_finance_and_settings_keys_for_a_general_admin_role(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->actingAs($owner)->post(route('roles.store'), ['name' => 'GA', 'preset_slug' => 'general_admin'])->assertRedirect();
        $role = $organization->roles()->where('name', 'GA')->firstOrFail();

        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, []);
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $member->getKey())
            ->firstOrFail();
        $membership->role_id = $role->getKey();
        $membership->save();
        $this->activate($member, $organization);

        $this->actingAs($member)->get(route('platform.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tenant.permissions')
                ->where('tenant.permissions', fn ($permissions) => $permissions->contains('finance.view')
                    && $permissions->contains('finance.export')
                    && $permissions->contains('settings.view')));
    }

    public function test_shared_tenant_permissions_exclude_settings_view_for_a_finance_preset_role(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->actingAs($owner)->post(route('roles.store'), ['name' => 'Fin', 'preset_slug' => 'finance'])->assertRedirect();
        $role = $organization->roles()->where('name', 'Fin')->firstOrFail();

        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, []);
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $member->getKey())
            ->firstOrFail();
        $membership->role_id = $role->getKey();
        $membership->save();
        $this->activate($member, $organization);

        $this->actingAs($member)->get(route('platform.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.permissions', fn ($permissions) => $permissions->contains('finance.view')
                    && ! $permissions->contains('settings.view')
                    && ! $permissions->contains('pos.access')));
    }
}
