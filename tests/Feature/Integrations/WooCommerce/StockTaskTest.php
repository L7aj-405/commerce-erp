<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Actions\Inventory\TransferInventoryAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\WooCommerce\CompleteWooCommerceStockTaskAction;
use App\Actions\WooCommerce\RecordWooCommerceStockTaskAction;
use App\Enums\WooCommerceStockTaskStatus;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceStockTask;
use Inertia\Testing\AssertableInertia as AssertableJson;
use Tests\Support\SalesTestCase;

/**
 * Part A — manual WooCommerce stock update queue. No test here calls the
 * WooCommerce API or touches the network: the whole feature is a local
 * operator checklist (§21 confirms no automatic write-back exists).
 */
class StockTaskTest extends SalesTestCase
{
    /** @return array{User, Organization, Store, Warehouse, ProductVariant, WooCommerceIntegration} */
    private function context(bool $syncStock = true): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Micro X', 'MIC-001', ['tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $integration = $this->createIntegration($organization, $syncStock, $warehouse);
        $this->linkVariantToWoo($organization, $integration, $variant);

        return [$owner, $organization, $store, $warehouse, $variant, $integration];
    }

    private function createIntegration(Organization $organization, bool $syncStock, Warehouse $warehouse): WooCommerceIntegration
    {
        $integration = new WooCommerceIntegration;
        $integration->organization_id = $organization->getKey();
        $integration->name = 'Boutique de test';
        $integration->store_url = 'https://shop.test';
        $integration->consumer_key = 'ck_test';
        $integration->consumer_secret = 'cs_test';
        $integration->default_warehouse_id = $warehouse->getKey();
        $integration->sync_stock = $syncStock;
        $integration->synced_product_count = 0;
        $integration->save();

        return $integration->fresh();
    }

    private function linkVariantToWoo(Organization $organization, WooCommerceIntegration $integration, ProductVariant $variant, string $externalProductId = '900'): ProductChannelIdentifier
    {
        $identifier = new ProductChannelIdentifier;
        $identifier->organization_id = $organization->getKey();
        $identifier->woocommerce_integration_id = $integration->getKey();
        $identifier->product_id = $variant->product_id;
        $identifier->product_variant_id = $variant->getKey();
        $identifier->source = WooCommerceIntegration::CHANNEL;
        $identifier->external_product_id = $externalProductId;
        $identifier->last_synced_at = now();
        $identifier->save();

        return $identifier;
    }

    /** @return array{SalesOrder, ProductVariant} */
    private function confirmedAndFulfilledOrder(User $owner, Organization $org, Store $store, Warehouse $warehouse, ProductVariant $variant, string $qty = '2.0000'): array
    {
        $order = $this->createDraftOrder($owner, $org, $store, null);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => $qty]);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());

        return [$fulfilled, $variant];
    }

    public function test_fulfilling_an_order_for_a_woo_linked_variant_creates_a_pending_task(): void
    {
        [$owner, $org, $store, $warehouse, $variant] = $this->context();

        [$order] = $this->confirmedAndFulfilledOrder($owner, $org, $store, $warehouse, $variant, '2.0000');

        $task = WooCommerceStockTask::query()->where('organization_id', $org->getKey())->firstOrFail();
        $this->assertSame(WooCommerceStockTaskStatus::Pending, $task->status);
        $this->assertSame('-2.0000', $task->quantity_delta);
        $this->assertSame($variant->getKey(), $task->product_variant_id);
        $this->assertSame($order->order_number, $task->source_reference);
        $this->assertSame('App\\Models\\InventoryMovement', $task->source_type);
    }

    public function test_a_local_only_product_never_creates_a_woo_task(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Local Only', 'LOC-001')->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        // No WooCommerceIntegration / ProductChannelIdentifier at all.

        $order = $this->createDraftOrder($owner, $organization, $store, null);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '1.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->assertSame(0, WooCommerceStockTask::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_an_integration_with_stock_sync_disabled_does_not_create_a_task(): void
    {
        [$owner, $org, $store, $warehouse, $variant] = $this->context(syncStock: false);

        $this->confirmedAndFulfilledOrder($owner, $org, $store, $warehouse, $variant, '2.0000');

        $this->assertSame(0, WooCommerceStockTask::query()->where('organization_id', $org->getKey())->count());
    }

    public function test_duplicate_processing_of_the_same_movement_never_duplicates_the_task(): void
    {
        [$owner, $org, $store, $warehouse, $variant] = $this->context();
        [$order] = $this->confirmedAndFulfilledOrder($owner, $org, $store, $warehouse, $variant, '2.0000');

        $this->assertSame(1, WooCommerceStockTask::query()->where('organization_id', $org->getKey())->count());

        // Simulate a retried request re-processing the very same authoritative
        // InventoryMovement — the reservation is already Consumed, but the
        // recorder itself must stay idempotent on the movement identity.
        $fresh = $order->fresh(['lines.allocations.inventoryReservation']);
        $reservation = $fresh->lines->first()->allocations->first()->inventoryReservation;
        app(RecordWooCommerceStockTaskAction::class)->recordForConsumedSale($org, $fresh, $reservation);

        $this->assertSame(1, WooCommerceStockTask::query()->where('organization_id', $org->getKey())->count());
    }

    public function test_an_internal_transfer_never_creates_a_woo_task(): void
    {
        [$owner, $org, , $warehouseA, $variant] = $this->context();
        $warehouseB = $this->createWarehouse($org, 'Dépôt 2', 'DEP2');

        app(TransferInventoryAction::class)->execute($owner, $org, $warehouseA, $warehouseB, $variant, '3.0000', 'Réassort interne');

        $this->assertSame(0, WooCommerceStockTask::query()->where('organization_id', $org->getKey())->count());
    }

    public function test_a_task_belongs_to_its_organization_and_is_invisible_to_another_tenant(): void
    {
        [$owner, $org, $store, $warehouse, $variant] = $this->context();
        [$order] = $this->confirmedAndFulfilledOrder($owner, $org, $store, $warehouse, $variant, '1.0000');
        $task = WooCommerceStockTask::query()->where('organization_id', $org->getKey())->firstOrFail();

        $otherOwner = User::factory()->create();
        $this->createOrganization($otherOwner);

        $this->actingAs($otherOwner)
            ->post(route('integrations.woocommerce.stock-tasks.complete', $task))
            ->assertNotFound();
    }

    public function test_completing_a_task_records_the_acting_user_and_time_and_never_touches_inventory(): void
    {
        [$owner, $org, $store, $warehouse, $variant] = $this->context();
        $this->confirmedAndFulfilledOrder($owner, $org, $store, $warehouse, $variant, '2.0000');
        $task = WooCommerceStockTask::query()->where('organization_id', $org->getKey())->firstOrFail();

        $balanceBefore = InventoryBalance::query()
            ->where('organization_id', $org->getKey())->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())->value('on_hand');

        $completed = app(CompleteWooCommerceStockTaskAction::class)->execute($owner, $task);

        $this->assertSame(WooCommerceStockTaskStatus::Completed, $completed->status);
        $this->assertSame($owner->getKey(), $completed->completed_by_user_id);
        $this->assertNotNull($completed->completed_at);

        $balanceAfter = InventoryBalance::query()
            ->where('organization_id', $org->getKey())->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())->value('on_hand');
        $this->assertSame($balanceBefore, $balanceAfter, 'completing the checklist item must never move inventory');
    }

    public function test_completing_an_already_completed_task_is_safely_idempotent(): void
    {
        [$owner, $org, $store, $warehouse, $variant] = $this->context();
        $this->confirmedAndFulfilledOrder($owner, $org, $store, $warehouse, $variant, '2.0000');
        $task = WooCommerceStockTask::query()->where('organization_id', $org->getKey())->firstOrFail();

        $first = app(CompleteWooCommerceStockTaskAction::class)->execute($owner, $task);
        $second = app(CompleteWooCommerceStockTaskAction::class)->execute($owner, $first->fresh());

        $this->assertSame(WooCommerceStockTaskStatus::Completed, $second->status);
        $this->assertSame($first->completed_at?->toIso8601String(), $second->completed_at?->toIso8601String());
    }

    public function test_completed_task_disappears_from_default_pending_queue_but_history_remains(): void
    {
        [$owner, $org, $store, $warehouse, $variant] = $this->context();
        $this->confirmedAndFulfilledOrder($owner, $org, $store, $warehouse, $variant, '2.0000');
        $task = WooCommerceStockTask::query()->where('organization_id', $org->getKey())->firstOrFail();
        app(CompleteWooCommerceStockTaskAction::class)->execute($owner, $task);

        $this->actingAs($owner)
            ->get(route('integrations.woocommerce.stock-tasks.index'))
            ->assertInertia(fn (AssertableJson $page) => $page->has('tasks.data', 0));

        $this->actingAs($owner)
            ->get(route('integrations.woocommerce.stock-tasks.index', ['status' => 'all']))
            ->assertInertia(fn (AssertableJson $page) => $page->has('tasks.data', 1));

        $this->assertDatabaseHas('woocommerce_stock_tasks', ['id' => $task->id, 'status' => 'completed']);
    }
}
