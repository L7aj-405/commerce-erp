<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\UpdateSalesOrderAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesTestCase;

class SalesOrderLifecycleTest extends SalesTestCase
{
    public function test_confirmation_creates_each_inventory_reservation_exactly_once(): void
    {
        [$owner, $organization, $store, $warehouse, $variant, $order] = $this->stockOrder('10.0000', '3.0000');
        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $this->assertSame('confirmed', $confirmed->status->value);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertSame($owner->id, $confirmed->confirmed_by_user_id);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 10, 'reserved' => 3]);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.confirmed', 'organization_id' => $organization->id]);
    }

    public function test_double_confirmation_is_rejected_without_duplicate_reservation(): void
    {
        [$owner, , , , , $order] = $this->stockOrder();
        $action = app(ConfirmSalesOrderAction::class);
        $action->execute($owner, $order);
        try {
            $action->execute($owner, $order->fresh());
            $this->fail('Expected double confirmation rejection.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_reservations', 1);
        }
    }

    public function test_confirmation_with_insufficient_stock_rolls_back_order_and_reservations(): void
    {
        [$owner, , , , , $order] = $this->stockOrder('2.0000', '3.0000');
        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order);
            $this->fail('Expected insufficient stock.');
        } catch (ValidationException) {
            $this->assertSame('draft', $order->fresh()->status->value);
            $this->assertDatabaseCount('inventory_reservations', 0);
            $this->assertNull($order->lines()->firstOrFail()->allocations()->firstOrFail()->inventory_reservation_id);
        }
    }

    public function test_multiple_line_confirmation_is_all_or_nothing(): void
    {
        [$owner, $organization, $store, $warehouse, , $order] = $this->stockOrder('10.0000', '2.0000');
        $second = $this->createProduct($organization, 'Unavailable', 'NONE')->variants->first();
        $this->addCatalogLine($owner, $order, $second, $warehouse, ['quantity' => '1.0000']);
        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order);
            $this->fail('Expected atomic rollback.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_reservations', 0);
            $this->assertSame('0.0000', $this->balance($organization, $warehouse, $order->lines()->first()->productVariant)->reserved);
        }
    }

    public function test_confirmed_unfulfilled_cancellation_releases_reservations_and_requires_reason(): void
    {
        [$owner, $organization, , $warehouse, $variant, $order] = $this->stockOrder('10.0000', '3.0000');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $this->expectException(ValidationException::class);
        try {
            app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), '');
        } finally {
            $cancelled = app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Customer request');
            $this->assertSame('cancelled', $cancelled->status->value);
            $this->assertSame('0.0000', $this->balance($organization, $warehouse, $variant)->reserved);
            $this->assertDatabaseHas('inventory_reservations', ['status' => 'released']);
        }
    }

    public function test_full_fulfillment_consumes_reservations_and_stock_once(): void
    {
        [$owner, $organization, , $warehouse, $variant, $order] = $this->stockOrder('10.0000', '3.0000');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $balance = $this->balance($organization, $warehouse, $variant);
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);
        $this->assertSame('7.0000', $balance->on_hand);
        $this->assertSame('0.0000', $balance->reserved);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'consumed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.fulfilled']);
        try {
            app(FulfillSalesOrderAction::class)->execute($owner, $fulfilled);
            $this->fail('Expected double fulfillment rejection.');
        } catch (ValidationException) {
            $this->assertSame('7.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
        }
    }

    public function test_fulfilled_order_cannot_be_cancelled(): void
    {
        [$owner, , , , , $order] = $this->stockOrder();
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->expectException(ValidationException::class);
        app(CancelSalesOrderAction::class)->execute($owner, $fulfilled, 'Not allowed');
    }

    public function test_confirmed_commercial_snapshot_and_lines_are_immutable(): void
    {
        [$owner, , , $warehouse, $variant, $order] = $this->stockOrder();
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        foreach ([
            fn () => app(UpdateSalesOrderAction::class)->execute($owner, $order->fresh(), ['customer_id' => null, 'sale_date' => '2026-08-25', 'currency_code' => 'EUR']),
            fn () => app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => '9.0000', 'discount_type' => 'none'], $order->lines()->first()),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected confirmed immutability.');
            } catch (ValidationException) {
                $this->assertSame('confirmed', $order->fresh()->status->value);
            }
        }
    }

    public function test_draft_cancellation_has_no_inventory_effect_and_cannot_be_repeated(): void
    {
        [$owner, , , , , $order] = $this->stockOrder();
        $cancelled = app(CancelSalesOrderAction::class)->execute($owner, $order, null);
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->expectException(ValidationException::class);
        app(CancelSalesOrderAction::class)->execute($owner, $cancelled);
    }

    /** @return array{User, Organization, Store, Warehouse, ProductVariant, SalesOrder} */
    private function stockOrder(string $stock = '10.0000', string $ordered = '2.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Stock Product')->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => $ordered]);

        return [$owner, $organization, $store, $warehouse, $variant, $order];
    }
}
