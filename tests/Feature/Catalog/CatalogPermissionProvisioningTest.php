<?php

namespace Tests\Feature\Catalog;

use App\Models\Permission;
use App\Models\User;
use App\Services\PermissionProvisioner;
use Tests\Support\CatalogTestCase;

class CatalogPermissionProvisioningTest extends CatalogTestCase
{
    public function test_future_organizations_receive_catalog_permissions(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->assertTrue($owner->hasPermission($organization, 'products.create'));
        $this->assertTrue($organization->roles()->where('slug', 'sales-employee')->firstOrFail()->permissions()->where('key', 'products.view')->exists());
    }

    public function test_provisioning_existing_organizations_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $provisioner = app(PermissionProvisioner::class);
        $provisioner->provisionAllOrganizations();
        $provisioner->provisionAllOrganizations();

        $permission = Permission::query()->where('key', 'products.view')->firstOrFail();
        $ownerRole = $organization->roles()->where('slug', 'owner')->firstOrFail();
        $this->assertSame(1, Permission::query()->where('key', 'products.view')->count());
        $this->assertSame(1, $ownerRole->permissions()->whereKey($permission->id)->count());
    }
}
