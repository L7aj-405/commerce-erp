<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Tests\Support\InventoryTestCase;

class InventoryPermissionProvisioningTest extends InventoryTestCase
{
    public function test_inventory_permissions_exist_and_are_provisioned_for_future_organizations(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $expected = [
            'warehouses.view', 'warehouses.create', 'warehouses.update', 'inventory.view',
            'inventory.opening', 'inventory.adjust', 'inventory.transfer', 'inventory.reserve', 'inventory.release', 'inventory.consume',
        ];

        foreach ($expected as $key) {
            $this->assertDatabaseHas('permissions', ['key' => $key]);
        }
        $this->assertEqualsCanonicalizing($expected, $organization->roles()->where('slug', 'admin')->firstOrFail()->permissions()->whereIn('key', $expected)->pluck('key')->all());
    }

    public function test_sales_employee_default_role_receives_operational_inventory_permissions(): void
    {
        $organization = $this->createOrganization(User::factory()->create());
        $inventoryKeys = $organization->roles()->where('slug', 'sales-employee')->firstOrFail()
            ->permissions()->where(fn ($query) => $query->where('key', 'like', 'inventory.%')->orWhere('key', 'like', 'warehouses.%'))
            ->pluck('key')->all();

        $this->assertEqualsCanonicalizing([
            'inventory.view',
            'inventory.transfer_requests.view',
            'inventory.transfer_requests.receive',
        ], $inventoryKeys);
    }
}
