<?php

namespace Tests\Feature\Pos;

use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\InventoryBalance;
use App\Models\InventoryReservation;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\User;
use App\Support\Decimal;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class PosOrganizationStockSourcingTest extends PosTestCase
{
    public function test_product_search_exposes_local_and_same_organization_total_availability(): void
    {
        [$owner, $organization, $store, $showroom, $main, $variant] = $this->context('2.0000', '8.0000');

        $this->actingAs($owner)->getJson(route('pos.products.index', [
            'warehouse_id' => $showroom->id,
            'search' => $variant->sku,
        ]))->assertOk()
            ->assertJsonPath('data.0.stock.available', '2.0000')
            ->assertJsonPath('data.0.stock.total_available', '10.0000');

        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignWarehouse = $this->createWarehouse($foreignOrganization, 'Foreign Warehouse');
        $foreignVariant = $this->createProduct($foreignOrganization, 'Foreign product', 'FOREIGN')->variants->first();
        $this->activate($foreignOwner, $foreignOrganization);
        $this->openStock($foreignOwner, $foreignOrganization, $foreignWarehouse, $foreignVariant, '999.0000');
        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $showroom->id, 'search' => $variant->sku]))
            ->assertOk()->assertJsonPath('data.0.stock.total_available', '10.0000');
    }

    public function test_product_with_zero_local_stock_can_be_added_when_company_stock_exists(): void
    {
        [$owner, $organization, $store, $showroom, $main, $variant] = $this->context('0.0000', '5.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $showroom);

        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), $this->draftLine($variant->id, $showroom->id, '1'))
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.local_available', '0.0000')
            ->assertJsonPath('active_sale.lines.0.total_available', '5.0000')
            ->assertJsonPath('active_sale.lines.0.remote_required', '1.0000')
            ->assertJsonPath('active_sale.lines.0.requires_replenishment', true)
            ->assertJsonPath('active_sale.availability_warnings', []);

        $this->assertDatabaseMissing('sales_order_inventory_allocations', [
            'sales_order_line_id' => $draft->lines()->firstOrFail()->id,
            'warehouse_id' => $showroom->id,
            'quantity' => 0,
        ]);
        $this->assertDatabaseHas('sales_order_inventory_allocations', ['warehouse_id' => $main->id, 'quantity' => 1]);
    }

    /**
     * A ProductVariant with zero company stock is a supplier special-order
     * candidate, not a rejected line — see the "POS + Supplier Procurement"
     * integration. The full requested quantity becomes "à approvisionner".
     */
    public function test_product_with_zero_company_stock_is_accepted_as_a_supplier_special_order(): void
    {
        [$owner, $organization, $store, $showroom, , $variant] = $this->context('0.0000', '0.0000');

        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $showroom->id, 'search' => $variant->sku]))
            ->assertOk()
            ->assertJsonPath('data.0.stock.available', '0.0000')
            ->assertJsonPath('data.0.stock.total_available', '0.0000');

        $draft = $this->createPosDraft($owner, $organization, $store, $showroom);
        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), $this->draftLine($variant->id, $showroom->id, '1'))
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.quantity', '1.0000')
            ->assertJsonPath('active_sale.lines.0.company_covered', '0.0000')
            ->assertJsonPath('active_sale.lines.0.to_procure', '1.0000')
            ->assertJsonPath('active_sale.lines.0.needs_procurement', true)
            ->assertJsonPath('active_sale.procurement_deficit', true);

        $this->assertSame(1, $draft->lines()->count(), 'no second line is created for the procured quantity');
    }

    /**
     * Requesting more than the company-wide total no longer blocks the line —
     * the excess becomes a supplier special-order requirement instead.
     */
    public function test_cart_accepts_a_quantity_above_the_company_total_as_a_procurement_deficit(): void
    {
        [$owner, $organization, $store, $showroom, , $variant] = $this->context('2.0000', '8.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $showroom);

        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), $this->draftLine($variant->id, $showroom->id, '5'))
            ->assertOk()->assertJsonPath('active_sale.lines.0.quantity', '5.0000');

        $line = $draft->lines()->firstOrFail();
        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $this->draftLine($variant->id, $showroom->id, '10'))
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.quantity', '10.0000')
            ->assertJsonPath('active_sale.lines.0.to_procure', '0.0000');

        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $this->draftLine($variant->id, $showroom->id, '11'))
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.quantity', '11.0000')
            ->assertJsonPath('active_sale.lines.0.company_covered', '10.0000')
            ->assertJsonPath('active_sale.lines.0.to_procure', '1.0000')
            ->assertJsonPath('active_sale.procurement_deficit', true);
        $this->assertSame(1, $draft->lines()->count());
    }

    public function test_pos_line_is_allocated_to_showroom_first_then_other_warehouses_by_id(): void
    {
        [$owner, $organization, $store, $showroom, $main, $variant] = $this->context('2.0000', '8.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $showroom);
        $line = $this->addCatalogLine($owner, $draft, $variant, $showroom, ['quantity' => '5.0000']);

        $allocations = $line->allocations()->orderBy('warehouse_id')->get();

        $this->assertSame('5.0000', $line->quantity);
        $this->assertSame('5.0000', $allocations->reduce(fn (string $total, $allocation) => Decimal::add($total, $allocation->quantity), '0.0000'));
        $this->assertDatabaseHas('sales_order_inventory_allocations', ['sales_order_line_id' => $line->id, 'warehouse_id' => $showroom->id, 'quantity' => 2]);
        $this->assertDatabaseHas('sales_order_inventory_allocations', ['sales_order_line_id' => $line->id, 'warehouse_id' => $main->id, 'quantity' => 3]);
    }

    public function test_pickup_using_remote_stock_is_paid_and_confirmed_but_not_auto_fulfilled(): void
    {
        [$owner, $organization, , $showroom, $main, $variant] = $this->context('2.0000', '8.0000');

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($showroom, [
            $this->posCatalogLine($variant, ['quantity' => '5.0000']),
        ]))->assertRedirect(route('pos.index'));

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame('unfulfilled', $order->fulfillment_status->value);
        $this->assertDatabaseHas('inventory_reservations', ['warehouse_id' => $showroom->id, 'quantity' => 2, 'status' => 'active']);
        $this->assertDatabaseHas('inventory_reservations', ['warehouse_id' => $main->id, 'quantity' => 3, 'status' => 'active']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $showroom->id, 'on_hand' => 2, 'reserved' => 2]);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $main->id, 'on_hand' => 8, 'reserved' => 3]);
        $this->assertDatabaseMissing('inventory_movements', ['movement_type' => 'transfer_out']);
        $this->assertDatabaseMissing('inventory_movements', ['movement_type' => 'transfer_in']);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'sales_order.pos_replenishment_required']);
        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('completedOrder.requires_replenishment', true)
            ->where('completedOrder.remote_required', '3.0000')
            ->where('completedOrder.fulfillment_status', 'unfulfilled')
            ->where('completedOrder.payment_status', 'paid'));
    }

    public function test_pickup_fully_allocated_to_showroom_keeps_immediate_fulfillment(): void
    {
        [$owner, , , $showroom, , $variant] = $this->context('5.0000', '0.0000');

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($showroom, [
            $this->posCatalogLine($variant, ['quantity' => '5.0000']),
        ]))->assertRedirect(route('pos.index'));

        $this->assertSame('fulfilled', SalesOrder::query()->firstOrFail()->fulfillment_status->value);
        $this->assertDatabaseHas('inventory_reservations', ['warehouse_id' => $showroom->id, 'quantity' => 5, 'status' => 'consumed']);
    }

    public function test_delivery_using_remote_stock_remains_unfulfilled_with_independent_payment_state(): void
    {
        [$owner, , , $showroom, $main, $variant] = $this->context('2.0000', '8.0000');

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($showroom, [
            $this->posCatalogLine($variant, ['quantity' => '5.0000']),
        ], ['fulfillment_mode' => 'delivery']))->assertRedirect(route('pos.index'));

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame('unfulfilled', $order->fulfillment_status->value);
        $this->assertDatabaseHas('inventory_reservations', ['warehouse_id' => $main->id, 'quantity' => 3, 'status' => 'active']);
    }

    public function test_reserved_stock_is_excluded_from_company_availability_and_second_order_cannot_reuse_it(): void
    {
        [$owner, $organization, , $showroom, $main, $variant] = $this->context('0.0000', '8.0000');
        $balance = InventoryBalance::query()->where('warehouse_id', $main->id)->where('product_variant_id', $variant->id)->firstOrFail();
        $balance->reserved = '6.0000';
        $balance->save();

        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $showroom->id, 'search' => $variant->sku]))
            ->assertOk()->assertJsonPath('data.0.stock.total_available', '2.0000');

        $this->actingAs($owner)->postJson(route('pos.sales.store'), $this->posPayload($showroom, [
            $this->posCatalogLine($variant, ['quantity' => '3.0000']),
        ], ['fulfillment_mode' => 'delivery']))->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(0, SalesOrder::query()->where('organization_id', $organization->id)->count());
    }

    public function test_second_order_cannot_reserve_company_stock_already_reserved_by_first_order(): void
    {
        [$owner, $organization, , $showroom, , $variant] = $this->context('0.0000', '5.0000');

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($showroom, [
            $this->posCatalogLine($variant, ['quantity' => '5.0000']),
        ], ['fulfillment_mode' => 'delivery']))->assertRedirect(route('pos.index'));

        $this->actingAs($owner)->postJson(route('pos.sales.store'), $this->posPayload($showroom, [
            $this->posCatalogLine($variant, ['quantity' => '1.0000']),
        ], ['fulfillment_mode' => 'delivery']))->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(1, SalesOrder::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('5.0000', InventoryReservation::query()->where('organization_id', $organization->id)->where('status', 'active')->firstOrFail()->quantity);
    }

    public function test_cancelling_confirmed_multi_warehouse_order_releases_every_reservation(): void
    {
        [$owner, $organization, $store, $showroom, $main, $variant] = $this->context('2.0000', '8.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $showroom);
        $this->addCatalogLine($owner, $draft, $variant, $showroom, ['quantity' => '5.0000']);
        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $draft);

        app(CancelSalesOrderAction::class)->execute($owner, $confirmed, 'Customer cancelled before preparation');

        $this->assertSame(0, InventoryReservation::query()->where('organization_id', $organization->id)->where('status', 'active')->count());
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $showroom->id, 'reserved' => 0]);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $main->id, 'reserved' => 0]);
    }

    public function test_foreign_organization_stock_is_never_an_allocation_candidate(): void
    {
        [$owner, $organization, $store, $showroom, , $variant] = $this->context('1.0000', '0.0000');
        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignWarehouse = $this->createWarehouse($foreignOrganization, 'Foreign Warehouse');
        $foreignVariant = $this->createProduct($foreignOrganization, 'Foreign', 'FOREIGN')->variants->first();
        $this->activate($foreignOwner, $foreignOrganization);
        $this->openStock($foreignOwner, $foreignOrganization, $foreignWarehouse, $foreignVariant, '100.0000');
        $this->activate($owner, $organization, $store);
        $draft = $this->createPosDraft($owner, $organization, $store, $showroom);

        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), $this->draftLine($variant->id, $showroom->id, '2'))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(0, SalesOrderInventoryAllocation::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, SalesOrderInventoryAllocation::query()->where('warehouse_id', $foreignWarehouse->id)->count());
    }

    private function context(string $showroomStock, string $mainStock): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $showroom = $this->createWarehouse($organization, 'Showroom');
        $main = $this->createWarehouse($organization, 'Main Warehouse');
        $variant = $this->createProduct($organization, 'Multi-site Product', 'MULTI-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        if (Decimal::compare($showroomStock, '0.0000') > 0) {
            $this->openStock($owner, $organization, $showroom, $variant, $showroomStock);
        }
        if (Decimal::compare($mainStock, '0.0000') > 0) {
            $this->openStock($owner, $organization, $main, $variant, $mainStock);
        }

        return [$owner, $organization, $store, $showroom, $main, $variant];
    }

    /** @return array<string, mixed> */
    private function draftLine(int $variantId, int $warehouseId, string $quantity): array
    {
        return [
            'line_type' => 'catalog',
            'product_variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ];
    }
}
