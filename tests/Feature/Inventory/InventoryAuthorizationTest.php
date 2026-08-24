<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Tests\Support\InventoryTestCase;

class InventoryAuthorizationTest extends InventoryTestCase
{
    public function test_owner_and_inventory_admin_can_manage_stock(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $admin, [
            'warehouses.view', 'warehouses.create', 'warehouses.update', 'inventory.view',
            'inventory.opening', 'inventory.adjust', 'inventory.reserve', 'inventory.release', 'inventory.consume',
        ], roleName: 'Inventory Admin');
        $this->activate($admin, $organization);

        $this->actingAs($owner)->get(route('inventory.stock.index'))->assertOk();
        $this->actingAs($admin)->post(route('inventory.warehouses.store'), ['name' => 'Admin Warehouse', 'code' => 'ADMIN'])->assertRedirect();
    }

    public function test_sales_employee_can_view_but_cannot_open_adjust_or_manage_warehouses(): void
    {
        $owner = User::factory()->create();
        $sales = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $sales, ['inventory.view'], roleName: 'Sales Employee');
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->activate($sales, $organization);

        $this->actingAs($sales)->get(route('inventory.stock.index'))->assertOk();
        $this->actingAs($sales)->get(route('inventory.warehouses.index'))->assertForbidden();
        $this->actingAs($sales)->post(route('inventory.opening.store'), ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => '1.0000'])->assertForbidden();
        $this->actingAs($sales)->post(route('inventory.adjustments.store'), ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'type' => 'adjustment_in', 'quantity' => '1.0000', 'reason' => 'Attack'])->assertForbidden();
    }

    public function test_permission_revocation_takes_effect_immediately(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $membership = $this->addOrganizationMember($organization, $staff, ['inventory.view'], roleName: 'Viewer');
        $this->activate($staff, $organization);

        $this->actingAs($staff)->get(route('inventory.stock.index'))->assertOk();
        $membership->role->permissions()->detach($this->permission('inventory.view'));
        $this->actingAs($staff)->get(route('inventory.stock.index'))->assertForbidden();
    }

    public function test_reservation_permissions_are_independently_enforced(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['inventory.view']);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->post(route('inventory.reservations.store'), [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => '1.0000',
        ])->assertForbidden();
    }
}
