<?php

namespace Tests\Feature\Pos;

use App\Models\InventoryMovement;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\PosTestCase;

class PosSaleCompletionTest extends PosTestCase
{
    public function test_catalog_pos_sale_reuses_full_sales_inventory_lifecycle(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockContext('10.0000');

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($variant, ['quantity' => '3.0000']),
        ]))->assertRedirect(route('pos.index'));

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame($organization->id, $order->organization_id);
        $this->assertSame($store->id, $order->store_id);
        $this->assertSame('pos', $order->source->value);
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('fulfilled', $order->fulfillment_status->value);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame($owner->id, $order->confirmed_by_user_id);
        $this->assertSame($owner->id, $order->fulfilled_by_user_id);
        $this->assertDatabaseHas('sales_order_inventory_allocations', ['warehouse_id' => $warehouse->id, 'quantity' => 3]);
        $this->assertDatabaseHas('inventory_reservations', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => 3, 'status' => 'consumed']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 7, 'reserved' => 0]);
        $this->assertDatabaseHas('inventory_movements', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'movement_type' => 'reservation_consumed', 'quantity' => -3]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'sales_order.created']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'sales_order.confirmed']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'sales_order.fulfilled']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_sales_employee_can_complete_pos_sale_without_direct_inventory_mutation_permissions(): void
    {
        [, $organization, $store, $warehouse, $variant] = $this->stockContext('4.0000');
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);

        $this->assertFalse($employee->hasPermission($organization, 'inventory.consume'));
        $this->actingAs($employee)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($variant, ['quantity' => '2.0000']),
        ]))->assertRedirect();

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame($employee->id, $order->created_by_user_id);
        $this->assertSame($employee->id, $order->confirmed_by_user_id);
        $this->assertSame($employee->id, $order->fulfilled_by_user_id);
        $this->assertSame('2.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
    }

    public function test_custom_pos_item_is_fulfilled_without_inventory_allocation(): void
    {
        [$owner, , , $warehouse] = $this->stockContext();

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCustomLine(['description' => 'Installation', 'quantity' => '2.0000', 'unit_price_excl_tax' => '50.0000']),
        ]))->assertRedirect();

        $order = SalesOrder::query()->with('lines')->firstOrFail();
        $this->assertSame('fulfilled', $order->fulfillment_status->value);
        $this->assertSame('custom', $order->lines->first()->line_type->value);
        $this->assertSame('100.0000', $order->total_incl_tax);
        $this->assertDatabaseCount('sales_order_inventory_allocations', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_catalog_price_is_snapshotted_and_forged_order_state_and_totals_are_ignored(): void
    {
        [$owner, , , $warehouse, $variant] = $this->stockContext();
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)]);
        $payload += [
            'source' => 'manual', 'status' => 'cancelled', 'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled', 'total_incl_tax' => '99999.0000',
            'confirmed_by_user_id' => 999999, 'fulfilled_by_user_id' => 999999,
        ];

        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();

        $order = SalesOrder::query()->with('lines')->firstOrFail();
        $this->assertSame('100.0000', $order->lines->first()->unit_price_excl_tax);
        $this->assertSame('100.0000', $order->total_incl_tax);
        $this->assertSame('pos', $order->source->value);
        $this->assertSame('paid', $order->payment_status->value);
    }

    public function test_sales_employee_cannot_override_catalog_price_through_pos(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockContext();
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);

        $this->actingAs($employee)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($variant, ['unit_price_excl_tax' => '1.0000']),
        ]))->assertForbidden();

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertSame('10.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
    }

    public function test_sales_employee_cannot_apply_discount_through_pos(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockContext();
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);

        $this->actingAs($employee)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($variant, ['discount_type' => 'fixed', 'discount_value' => '10.0000']),
        ]))->assertForbidden();

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertSame('0.0000', $this->balance($organization, $warehouse, $variant)->reserved);
    }

    public function test_insufficient_stock_rolls_back_entire_pos_completion(): void
    {
        [$owner, $organization, , $warehouse, $variant] = $this->stockContext('1.0000');

        $this->actingAs($owner)->postJson(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($variant, ['quantity' => '2.0000']),
        ]))->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $balance = $this->balance($organization, $warehouse, $variant);
        $this->assertSame('1.0000', $balance->on_hand);
        $this->assertSame('0.0000', $balance->reserved);
        $this->assertDatabaseMissing('inventory_movements', ['movement_type' => 'reservation_consumed']);
    }

    public function test_same_client_operation_id_is_idempotent_across_double_submission(): void
    {
        [$owner, $organization, , $warehouse, $variant] = $this->stockContext('5.0000');
        $operationId = (string) Str::uuid();
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)], ['client_operation_id' => $operationId]);

        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_order_lines', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame(1, $this->movementCount('reservation_consumed'));
        $this->assertSame('4.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
    }

    public function test_duplicate_barcode_equivalent_lines_are_consolidated_before_completion(): void
    {
        [$owner, , , $warehouse, $variant] = $this->stockContext('5.0000');

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($variant),
            $this->posCatalogLine($variant),
        ]))->assertRedirect();

        $order = SalesOrder::query()->with('lines')->firstOrFail();
        $this->assertCount(1, $order->lines);
        $this->assertSame('2.0000', $order->lines->first()->quantity);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    private function stockContext(string $stock = '10.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'POS Product', 'POS-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return [$owner, $organization, $store, $warehouse, $variant];
    }

    private function movementCount(string $type): int
    {
        return InventoryMovement::query()->where('movement_type', $type)->count();
    }
}
