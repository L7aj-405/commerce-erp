<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Tests\Support\SalesTestCase;

class SalesPermissionProvisioningTest extends SalesTestCase
{
    public function test_sales_permissions_are_provisioned_for_future_organizations(): void
    {
        $organization = $this->createOrganization(User::factory()->create());
        $expected = [
            'customers.view', 'customers.create', 'customers.update', 'sales_orders.view', 'sales_orders.create',
            'sales_orders.update', 'sales_orders.confirm', 'sales_orders.cancel', 'sales_orders.fulfill',
            'sales_orders.override_price', 'sales_orders.apply_discount',
        ];
        foreach ($expected as $key) {
            $this->assertDatabaseHas('permissions', ['key' => $key]);
        }
        $this->assertEqualsCanonicalizing($expected, $organization->roles()->where('slug', 'admin')->firstOrFail()->permissions()->whereIn('key', $expected)->pluck('key')->all());
    }

    public function test_sales_employee_role_excludes_sensitive_sales_and_inventory_permissions(): void
    {
        $organization = $this->createOrganization(User::factory()->create());
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $this->assertTrue($role->permissions()->where('key', 'sales_orders.confirm')->exists());
        $this->assertTrue($role->permissions()->where('key', 'sales_orders.fulfill')->exists());
        foreach (['sales_orders.cancel', 'sales_orders.override_price', 'sales_orders.apply_discount', 'inventory.reserve', 'inventory.consume', 'inventory.adjust'] as $key) {
            $this->assertFalse($role->permissions()->where('key', $key)->exists(), "Unexpected permission: {$key}");
        }
    }
}
