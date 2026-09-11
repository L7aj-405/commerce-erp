<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Tests\Support\PlatformTestCase;

class RolePresetTest extends PlatformTestCase
{
    public function test_owner_can_create_general_admin_preset_role_with_finance_access(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('roles.store'), [
            'name' => 'GA Custom',
            'preset_slug' => 'general_admin',
        ])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'GA Custom')->firstOrFail();

        $this->assertFalse($role->is_system);
        $this->assertSame('general_admin', $role->preset_slug);

        $permissions = $role->permissions()->pluck('key')->sort()->values()->all();
        $expected = collect(config('platform.presets.general_admin.permissions'))->sort()->values()->all();
        $this->assertSame($expected, $permissions);

        // Business decision: General Admin can see AND export Finance.
        $this->assertContains('finance.view', $permissions);
        $this->assertContains('finance.export', $permissions);
        $this->assertContains('finance.receivables.view', $permissions);
        $this->assertContains('settings.view', $permissions);
    }

    public function test_sales_commercial_preset_matches_the_existing_sales_employee_bundle_exactly(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('roles.store'), [
            'name' => 'Sales Custom',
            'preset_slug' => 'sales_commercial',
        ])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'Sales Custom')->firstOrFail();
        $salesEmployee = Role::query()->where('organization_id', $organization->getKey())->where('slug', 'sales-employee')->firstOrFail();

        $this->assertSame(
            $salesEmployee->permissions()->pluck('key')->sort()->values()->all(),
            $role->permissions()->pluck('key')->sort()->values()->all(),
        );

        $permissions = $role->permissions()->pluck('key')->all();
        $this->assertNotContains('finance.view', $permissions);
        $this->assertNotContains('integrations.manage', $permissions);
        $this->assertNotContains('roles.create', $permissions);
        $this->assertNotContains('settings.update', $permissions);
        $this->assertNotContains('members.create', $permissions);
        $this->assertNotContains('sales_orders.override_price', $permissions);
        $this->assertNotContains('sales_orders.apply_discount', $permissions);
        $this->assertNotContains('payments.reverse', $permissions);
        $this->assertNotContains('payments.backdate', $permissions);
        $this->assertNotContains('invoices.backdate', $permissions);
        $this->assertNotContains('inventory.adjust', $permissions);
    }

    public function test_finance_preset_grants_exactly_the_specified_set(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('roles.store'), [
            'name' => 'Finance Custom',
            'preset_slug' => 'finance',
        ])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'Finance Custom')->firstOrFail();
        $permissions = $role->permissions()->pluck('key')->sort()->values()->all();

        $this->assertSame([
            'customers.view',
            'finance.export',
            'finance.receivables.view',
            'finance.view',
            'invoices.view',
            'payments.view',
            'quotations.view',
        ], $permissions);

        $this->assertNotContains('pos.access', $permissions);
        $this->assertNotContains('products.view', $permissions);
        $this->assertNotContains('inventory.adjust', $permissions);
        $this->assertNotContains('integrations.view', $permissions);
        $this->assertNotContains('members.view', $permissions);
        $this->assertNotContains('roles.view', $permissions);
        $this->assertNotContains('settings.view', $permissions);
        $this->assertNotContains('settings.update', $permissions);
    }

    public function test_custom_role_without_preset_starts_with_zero_permissions(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('roles.store'), ['name' => 'Blank Role'])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'Blank Role')->firstOrFail();

        $this->assertFalse($role->is_system);
        $this->assertNull($role->preset_slug);
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_role_created_from_preset_is_unaffected_by_later_config_changes(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->post(route('roles.store'), [
            'name' => 'Finance Snapshot',
            'preset_slug' => 'finance',
        ])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'Finance Snapshot')->firstOrFail();
        $originalPermissions = $role->permissions()->pluck('key')->sort()->values()->all();

        // Mutate the preset definition at runtime — must never retroactively
        // change a role created earlier from it (preset_slug is metadata only).
        config(['platform.presets.finance.permissions' => ['finance.view']]);

        $role->refresh();
        $this->assertSame($originalPermissions, $role->permissions()->pluck('key')->sort()->values()->all());
    }

    public function test_admin_system_role_member_creating_general_admin_preset_only_gets_permissions_they_hold(): void
    {
        $owner = User::factory()->create();
        $adminUser = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $adminRole = Role::query()->where('organization_id', $organization->getKey())->where('slug', 'admin')->firstOrFail();
        $this->attachMembership($organization, $adminUser, $adminRole);
        $this->activate($adminUser, $organization);

        $this->actingAs($adminUser)->post(route('roles.store'), [
            'name' => 'GA via admin',
            'preset_slug' => 'general_admin',
        ])->assertRedirect();

        $role = Role::query()->where('organization_id', $organization->getKey())->where('name', 'GA via admin')->firstOrFail();
        $permissions = $role->permissions()->pluck('key')->sort()->values()->all();
        $adminPermissions = $adminRole->permissions()->pluck('key')->sort()->values()->all();

        // The protected `admin` system role does NOT hold finance.*/settings.view
        // (business decision 4/9), so the escalation guard must silently narrow
        // the preset to what the actor actually holds — never grant the rest.
        $this->assertSame($adminPermissions, $permissions);
        $this->assertNotContains('finance.view', $permissions);
        $this->assertNotContains('finance.export', $permissions);
        $this->assertNotContains('settings.view', $permissions);
    }

    private function attachMembership(Organization $organization, User $user, Role $role): void
    {
        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->getKey();
        $membership->user_id = $user->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = 'active';
        $membership->save();
    }
}
