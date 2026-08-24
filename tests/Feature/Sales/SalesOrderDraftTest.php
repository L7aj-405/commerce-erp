<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\UpdateSalesOrderAction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Support\SalesTestCase;

class SalesOrderDraftTest extends SalesTestCase
{
    public function test_draft_order_requires_active_store_and_derives_store_and_tenant_server_side(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->actingAs($owner)->post(route('sales.orders.store'), [
            'organization_id' => 999999, 'store_id' => 999999, 'sale_date' => '2026-08-24', 'currency_code' => 'MAD',
        ])->assertRedirect()->getSession()->get('errors');
        $this->assertNull($order);
        $this->assertDatabaseHas('sales_orders', ['organization_id' => $organization->id, 'store_id' => $store->id, 'status' => 'draft', 'payment_status' => 'unpaid']);

        $owner->active_store_id = null;
        $owner->save();
        $this->actingAs($owner)->post(route('sales.orders.store'), ['sale_date' => '2026-08-24', 'currency_code' => 'MAD'])->assertStatus(409);
    }

    public function test_order_numbers_are_sequential_and_organization_scoped(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $first = $this->createDraftOrder($owner, $organization, $store);
        $second = $this->createDraftOrder($owner, $organization, $store);
        $this->assertSame('SO-000001', $first->order_number);
        $this->assertSame('SO-000002', $second->order_number);
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $storeB = $this->createStore($organizationB, $ownerB);
        $this->assertSame('SO-000001', $this->createDraftOrder($ownerB, $organizationB, $storeB)->order_number);
    }

    public function test_sale_date_and_operational_ordered_at_are_distinct_sales_fields(): void
    {
        [$owner, $organization, $store] = $this->context();
        $order = $this->createDraftOrder($owner, $organization, $store, overrides: ['sale_date' => '2026-07-31']);
        $this->assertSame('2026-07-31', $order->sale_date->toDateString());
        $this->assertNotNull($order->ordered_at);
        $this->assertSame('unpaid', $order->payment_status->value);
        $this->assertArrayNotHasKey('invoice_date', $order->getAttributes());
        $this->assertArrayNotHasKey('payment_date', $order->getAttributes());
    }

    public function test_catalog_line_snapshots_product_price_tax_and_allocation_without_reserving_draft_stock(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->context();
        $tax = $this->createTaxRate($organization, 'VAT', '20.0000');
        $product = $this->createProduct($organization, 'Camera', 'CAM-1', ['tax_rate_id' => $tax->id, 'default_sale_price' => '100.0000']);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $product->variants->first(), $warehouse, ['quantity' => '2.0000']);

        $this->assertSame('Camera', $line->product_name);
        $this->assertSame('CAM-1', $line->sku);
        $this->assertSame('200.0000', $line->subtotal_excl_tax);
        $this->assertSame('40.0000', $line->tax_amount);
        $this->assertSame('240.0000', $line->total_incl_tax);
        $this->assertDatabaseHas('sales_order_inventory_allocations', ['sales_order_line_id' => $line->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2, 'inventory_reservation_id' => null]);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_custom_line_calculates_totals_and_never_creates_inventory_allocation(): void
    {
        [$owner, $organization, $store] = $this->context();
        $tax = $this->createTaxRate($organization, 'VAT', '20.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCustomLine($owner, $order, ['quantity' => '1.5000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => $tax->id]);
        $this->assertSame('150.0000', $line->subtotal_excl_tax);
        $this->assertSame('30.0000', $line->tax_amount);
        $this->assertSame('180.0000', $line->total_incl_tax);
        $this->assertDatabaseMissing('sales_order_inventory_allocations', ['sales_order_line_id' => $line->id]);
    }

    public function test_line_discount_and_order_totals_are_calculated_server_side(): void
    {
        [$owner, $organization, $store] = $this->context();
        $tax = $this->createTaxRate($organization, 'VAT', '20.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCustomLine($owner, $order, ['quantity' => '2.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => $tax->id, 'discount_type' => 'percentage', 'discount_value' => '10.0000']);
        $order->refresh();
        $this->assertSame('20.0000', $line->discount_amount);
        $this->assertSame('180.0000', $line->taxable_amount);
        $this->assertSame('36.0000', $line->tax_amount);
        $this->assertSame('216.0000', $order->total_incl_tax);
    }

    public function test_forged_line_and_order_totals_and_status_fields_are_ignored(): void
    {
        [$owner, $organization, $store] = $this->context();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'custom', 'description' => 'Secure', 'quantity' => '2.0000', 'unit_price_excl_tax' => '10.0000',
            'discount_type' => 'none', 'discount_value' => '0', 'subtotal_excl_tax' => '9999', 'tax_total' => '9999',
            'discount_total' => '9999', 'total_incl_tax' => '9999', 'status' => 'confirmed',
            'payment_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'confirmed_by_user_id' => 999,
        ])->assertRedirect();
        $order->refresh();
        $line = $order->lines()->firstOrFail();
        $this->assertSame('20.0000', $line->subtotal_excl_tax);
        $this->assertSame('20.0000', $order->total_incl_tax);
        $this->assertSame('draft', $order->status->value);
        $this->assertSame('unpaid', $order->payment_status->value);
        $this->assertNull($order->confirmed_by_user_id);
    }

    public function test_customer_snapshot_does_not_change_when_customer_changes(): void
    {
        [$owner, $organization, $store] = $this->context();
        $customer = $this->createCustomer($organization, 'Original', ['phone' => '111', 'email' => 'old@example.test']);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $customer->display_name = 'Changed';
        $customer->phone = '222';
        $customer->save();
        $order->refresh();
        $this->assertSame('Original', $order->customer_name);
        $this->assertSame('111', $order->customer_phone);
        $this->assertSame('old@example.test', $order->customer_email);
    }

    public function test_product_and_price_snapshot_do_not_change_when_catalog_changes(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->context();
        $product = $this->createProduct($organization, 'Original Product', 'ORIG', ['default_sale_price' => '50.0000']);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $product->variants->first(), $warehouse);
        $product->name = 'Changed Product';
        $product->save();
        $variant = $product->variants->first();
        $variant->default_sale_price = '999.0000';
        $variant->save();
        $line->refresh();
        $this->assertSame('Original Product', $line->product_name);
        $this->assertSame('50.0000', $line->unit_price_excl_tax);
    }

    public function test_draft_header_and_line_can_be_updated_and_totals_recalculated(): void
    {
        [$owner, $organization, $store] = $this->context();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCustomLine($owner, $order);
        app(UpdateSalesOrderAction::class)->execute($owner, $order, ['customer_id' => null, 'sale_date' => '2026-08-25', 'currency_code' => 'EUR', 'notes' => 'Updated']);
        app(SaveSalesOrderLineAction::class)->execute($owner, $order, ['line_type' => 'custom', 'description' => 'Updated service', 'quantity' => '2.0000', 'unit_price_excl_tax' => '75.0000', 'tax_rate_id' => null, 'discount_type' => 'none', 'discount_value' => '0'], $line);
        $order->refresh();
        $this->assertSame('EUR', $order->currency_code);
        $this->assertSame('150.0000', $order->total_incl_tax);
    }

    public function test_decimal_rounding_is_half_up_at_four_places(): void
    {
        [$owner, $organization, $store] = $this->context();
        $tax = $this->createTaxRate($organization, 'VAT', '20.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCustomLine($owner, $order, ['quantity' => '1.0000', 'unit_price_excl_tax' => '0.3333', 'tax_rate_id' => $tax->id]);
        $this->assertSame('0.0667', $line->tax_amount);
        $this->assertSame('0.4000', $line->total_incl_tax);
    }

    /** @return array{User, Organization, Store, Warehouse} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);

        return [$owner, $organization, $store, $warehouse];
    }
}
