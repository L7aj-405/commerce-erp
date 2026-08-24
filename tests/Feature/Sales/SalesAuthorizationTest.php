<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\User;
use Tests\Support\SalesTestCase;

class SalesAuthorizationTest extends SalesTestCase
{
    public function test_sales_employee_can_create_confirm_and_fulfill_without_direct_inventory_permissions(): void
    {
        $owner = User::factory()->create();
        $sales = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '5.0000');
        $this->addDefaultSalesEmployee($organization, $store, $sales);
        $order = $this->createDraftOrder($sales, $organization, $store);
        $this->addCatalogLine($sales, $order, $variant, $warehouse, ['quantity' => '2.0000']);
        app(ConfirmSalesOrderAction::class)->execute($sales, $order);
        app(FulfillSalesOrderAction::class)->execute($sales, $order->fresh());
        $this->assertSame('3.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertFalse($sales->hasPermission($organization, 'inventory.reserve'));
        $this->assertFalse($sales->hasPermission($organization, 'inventory.consume'));
    }

    public function test_sales_employee_cannot_override_catalog_price_or_apply_discount(): void
    {
        $owner = User::factory()->create();
        $sales = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Product', null, ['default_sale_price' => '100.0000'])->variants->first();
        $this->addDefaultSalesEmployee($organization, $store, $sales);
        $order = $this->createDraftOrder($sales, $organization, $store);
        $this->actingAs($sales)->post(route('sales.orders.lines.store', $order), ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => '1.0000', 'unit_price_excl_tax' => '1.0000', 'discount_type' => 'none'])->assertForbidden();
        $this->actingAs($sales)->post(route('sales.orders.lines.store', $order), ['line_type' => 'custom', 'description' => 'Service', 'quantity' => '1.0000', 'unit_price_excl_tax' => '100.0000', 'discount_type' => 'percentage', 'discount_value' => '10.0000'])->assertForbidden();
        $this->assertDatabaseCount('sales_order_lines', 0);
    }

    public function test_sales_employee_cannot_cancel_or_directly_adjust_inventory(): void
    {
        $owner = User::factory()->create();
        $sales = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->addDefaultSalesEmployee($organization, $store, $sales);
        $order = $this->createDraftOrder($sales, $organization, $store);
        $this->actingAs($sales)->post(route('sales.orders.cancel', $order), ['reason' => 'Attack'])->assertForbidden();
        $this->actingAs($sales)->post(route('inventory.adjustments.store'), ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'type' => 'adjustment_in', 'quantity' => '10.0000', 'reason' => 'Attack'])->assertForbidden();
    }

    public function test_owner_and_admin_have_sensitive_sales_permissions(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->addOrganizationMember($organization, $admin, ['customers.view', 'customers.create', 'customers.update', 'sales_orders.view', 'sales_orders.create', 'sales_orders.update', 'sales_orders.confirm', 'sales_orders.cancel', 'sales_orders.fulfill', 'sales_orders.override_price', 'sales_orders.apply_discount'], roleName: 'Sales Admin');
        $this->addStoreMember($store, $admin);
        $this->activate($admin, $organization, $store);
        $this->actingAs($owner)->get(route('sales.orders.index'))->assertOk();
        $this->actingAs($admin)->get(route('sales.orders.index'))->assertOk();
        $this->assertTrue($admin->hasPermission($organization, 'sales_orders.override_price'));
        $this->assertTrue($admin->hasPermission($organization, 'sales_orders.cancel'));
    }

    public function test_sales_permission_revocation_is_immediate_without_new_login(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $membership = $this->addOrganizationMember($organization, $staff, ['sales_orders.view'], roleName: 'Sales Viewer');
        $this->addStoreMember($store, $staff);
        $this->activate($staff, $organization, $store);
        $this->actingAs($staff)->get(route('sales.orders.index'))->assertOk();
        $membership->role->permissions()->detach($this->permission('sales_orders.view'));
        $this->actingAs($staff)->get(route('sales.orders.index'))->assertForbidden();
    }

    public function test_public_inventory_endpoint_cannot_manipulate_sales_owned_reservation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $reservation = $order->lines()->first()->allocations()->first()->inventoryReservation;
        $this->actingAs($owner)->post(route('inventory.reservations.release', $reservation))->assertForbidden();
        $this->actingAs($owner)->post(route('inventory.reservations.consume', $reservation))->assertForbidden();
        $this->assertDatabaseHas('inventory_reservations', ['id' => $reservation->id, 'status' => 'active']);
    }
}
