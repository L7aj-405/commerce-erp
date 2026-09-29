<?php

namespace Tests\Feature\Contacts;

use App\Models\Permission;
use App\Models\User;
use App\Services\PermissionProvisioner;
use Tests\Support\PlatformTestCase;

class ContactPermissionProvisioningTest extends PlatformTestCase
{
    public function test_future_organizations_receive_contact_permissions_on_system_roles(): void
    {
        $organization = $this->createOrganization(User::factory()->create());

        $expected = ['contacts.view', 'contacts.create', 'contacts.update', 'contacts.archive'];
        $owner = $organization->roles()->where('slug', 'owner')->firstOrFail();
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();

        $this->assertEqualsCanonicalizing($expected, $owner->permissions()->whereIn('key', $expected)->pluck('key')->all());
        $this->assertEqualsCanonicalizing($expected, $admin->permissions()->whereIn('key', $expected)->pluck('key')->all());
    }

    public function test_existing_organization_admin_gets_contact_permissions_after_idempotent_provisioning(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $expected = ['contacts.view', 'contacts.create', 'contacts.update', 'contacts.archive'];

        $admin->permissions()->detach(Permission::query()->whereIn('key', $expected)->pluck('id'));

        $provisioner = app(PermissionProvisioner::class);
        $provisioner->provisionAllOrganizations();
        $provisioner->provisionAllOrganizations();

        $this->assertEqualsCanonicalizing($expected, $admin->permissions()->whereIn('key', $expected)->pluck('key')->all());
        foreach ($expected as $key) {
            $this->assertSame(1, Permission::query()->where('key', $key)->count());
        }
    }

    public function test_contact_permission_provisioning_does_not_mutate_custom_roles(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $custom = $this->createRole($organization, ['customers.view'], 'Custom Viewer');

        app(PermissionProvisioner::class)->provisionAllOrganizations();

        $this->assertSame(['customers.view'], $custom->permissions()->pluck('key')->sort()->values()->all());
        $this->assertFalse($custom->permissions()->where('key', 'contacts.view')->exists());
    }
}
