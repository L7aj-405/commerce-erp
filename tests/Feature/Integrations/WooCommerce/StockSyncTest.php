<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WooCommerceSyncRun;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Tests\Support\WooCommerceTestCase;

class StockSyncTest extends WooCommerceTestCase
{
    public function test_stock_sync_disabled_never_touches_the_inventory_ledger(): void
    {
        [$owner, $organization, , $warehouse, $integration] = $this->wooContext(syncStock: false);

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-1', 'manage_stock' => true, 'stock_quantity' => 15]),
        ]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(0, $run->stock_adjustments);
        $this->assertSame(0, InventoryMovement::where('organization_id', $organization->getKey())->count());
        $this->assertDatabaseMissing('inventory_balances', [
            'organization_id' => $organization->getKey(),
            'warehouse_id' => $warehouse->getKey(),
        ]);
    }

    public function test_stock_sync_reconciles_the_difference_through_an_adjustment_movement(): void
    {
        [$owner, $organization, , $warehouse, $integration] = $this->wooContext(syncStock: true);

        // First pass: catalogue only (no managed stock yet) so the ERP variant exists.
        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-1', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        // Second pass: Woo now reports 15 on hand -> +5 adjustment, never an overwrite.
        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-1', 'manage_stock' => true, 'stock_quantity' => 15]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(1, $run->stock_adjustments);
        $this->assertSame(WooCommerceSyncRun::STATUS_COMPLETED, $run->status);

        $movement = InventoryMovement::query()
            ->where('organization_id', $organization->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('movement_type', InventoryMovementType::AdjustmentIn)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('10.0000', $movement->quantity_before);
        $this->assertSame('15.0000', $movement->quantity_after);
        $this->assertSame('15.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
    }

    public function test_initial_stock_sync_applies_only_the_aggregate_delta_once(): void
    {
        [$owner, $organization, , $warehouse, $integration] = $this->wooContext(syncStock: true);

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-INITIAL', 'manage_stock' => true, 'stock_quantity' => 2]),
        ]);
        $firstRun = $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame(1, $firstRun->stock_adjustments);
        $this->assertSame('2.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertSame(1, InventoryMovement::where('organization_id', $organization->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('movement_type', InventoryMovementType::AdjustmentIn)
            ->count());

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-INITIAL', 'manage_stock' => true, 'stock_quantity' => 2]),
        ]);
        $secondRun = $this->runSync($integration, $owner);

        $this->assertSame(0, $secondRun->stock_adjustments);
        $this->assertSame('2.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertSame(1, InventoryMovement::where('organization_id', $organization->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('movement_type', InventoryMovementType::AdjustmentIn)
            ->count());
    }

    public function test_stock_sync_reconciles_against_organization_wide_stock_and_applies_delta_to_sync_warehouse_only(): void
    {
        [$owner, $organization, , $syncWarehouse, $integration] = $this->wooContext(syncStock: true);
        $otherWarehouse = $this->createWarehouse($organization, 'Dépôt principal', 'MAIN');

        // First pass: catalogue only so the ERP variant exists before stock is seeded.
        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->openStock($owner, $organization, $syncWarehouse, $variant, '2.0000');
        $this->openStock($owner, $organization, $otherWarehouse, $variant, '7.0000');

        // Woo reports 10 total. ERP already has 9 across warehouses, so only +1
        // is added to the configured Woo sync warehouse. The sync warehouse must
        // not be overwritten to 10.
        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG', 'manage_stock' => true, 'stock_quantity' => 10]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(1, $run->stock_adjustments);
        $this->assertSame('3.0000', $this->balance($organization, $syncWarehouse, $variant)->on_hand);
        $this->assertSame('7.0000', $this->balance($organization, $otherWarehouse, $variant)->on_hand);

        $movement = InventoryMovement::query()
            ->where('organization_id', $organization->getKey())
            ->where('warehouse_id', $syncWarehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('movement_type', InventoryMovementType::AdjustmentIn)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('1.0000', $movement->quantity);
        $this->assertSame('2.0000', $movement->quantity_before);
        $this->assertSame('3.0000', $movement->quantity_after);
        $this->assertStringContainsString('Woo 10.0000', $run->message);
        $this->assertStringContainsString('ERP avant 9.0000', $run->message);
        $this->assertStringContainsString('écart +1.0000', $run->message);
        $this->assertStringContainsString($syncWarehouse->name.' +1.0000', $run->message);
        $this->assertStringContainsString('ERP après 10.0000', $run->message);
    }

    public function test_a_matching_stock_level_produces_no_movement(): void
    {
        [$owner, $organization, , $warehouse, $integration] = $this->wooContext(syncStock: true);

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-1', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->openStock($owner, $organization, $warehouse, $variant, '8.0000');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-1', 'manage_stock' => true, 'stock_quantity' => 8]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(0, $run->stock_adjustments);
        $this->assertSame(1, InventoryMovement::where('organization_id', $organization->getKey())
            ->where('product_variant_id', $variant->getKey())->count());
    }

    public function test_matching_aggregate_stock_across_warehouses_produces_no_movement(): void
    {
        [$owner, $organization, , $syncWarehouse, $integration] = $this->wooContext(syncStock: true);
        $otherWarehouse = $this->createWarehouse($organization, 'Dépôt principal', 'MAIN');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-MATCH', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->openStock($owner, $organization, $syncWarehouse, $variant, '2.0000');
        $this->openStock($owner, $organization, $otherWarehouse, $variant, '6.0000');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-MATCH', 'manage_stock' => true, 'stock_quantity' => 8]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(0, $run->stock_adjustments);
        $this->assertSame(2, InventoryMovement::where('organization_id', $organization->getKey())
            ->where('product_variant_id', $variant->getKey())->count());
        $this->assertSame('2.0000', $this->balance($organization, $syncWarehouse, $variant)->on_hand);
        $this->assertSame('6.0000', $this->balance($organization, $otherWarehouse, $variant)->on_hand);
    }

    public function test_negative_aggregate_delta_is_applied_only_to_the_sync_warehouse_when_it_can_absorb_it(): void
    {
        [$owner, $organization, , $syncWarehouse, $integration] = $this->wooContext(syncStock: true);
        $otherWarehouse = $this->createWarehouse($organization, 'Dépôt principal', 'MAIN');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-OUT', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->openStock($owner, $organization, $syncWarehouse, $variant, '5.0000');
        $this->openStock($owner, $organization, $otherWarehouse, $variant, '3.0000');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-OUT', 'manage_stock' => true, 'stock_quantity' => 6]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(1, $run->stock_adjustments);
        $this->assertSame('3.0000', $this->balance($organization, $syncWarehouse, $variant)->on_hand);
        $this->assertSame('3.0000', $this->balance($organization, $otherWarehouse, $variant)->on_hand);

        $movement = InventoryMovement::query()
            ->where('organization_id', $organization->getKey())
            ->where('warehouse_id', $syncWarehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('movement_type', InventoryMovementType::AdjustmentOut)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('-2.0000', $movement->quantity);
        $this->assertSame('5.0000', $movement->quantity_before);
        $this->assertSame('3.0000', $movement->quantity_after);
    }

    public function test_negative_aggregate_delta_uses_primary_then_secondary_when_primary_is_insufficient(): void
    {
        [$owner, $organization, , $syncWarehouse, $integration] = $this->wooContext(syncStock: true);
        $otherWarehouse = $this->createWarehouse($organization, 'Dépôt principal', 'MAIN');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-BLOCK', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->openStock($owner, $organization, $syncWarehouse, $variant, '1.0000');
        $this->openStock($owner, $organization, $otherWarehouse, $variant, '9.0000');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-BLOCK', 'manage_stock' => true, 'stock_quantity' => 5]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(2, $run->stock_adjustments);
        $this->assertSame('0.0000', $this->balance($organization, $syncWarehouse, $variant)->on_hand);
        $this->assertSame('5.0000', $this->balance($organization, $otherWarehouse, $variant)->on_hand);
        $this->assertSame(2, InventoryMovement::where('organization_id', $organization->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('movement_type', InventoryMovementType::AdjustmentOut)
            ->count());
        $this->assertEmpty($run->errors);
        $this->assertStringContainsString($syncWarehouse->name.' -1.0000', $run->message);
        $this->assertStringContainsString($otherWarehouse->name.' -4.0000', $run->message);
        $this->assertStringContainsString('ERP après 5.0000', $run->message);
    }

    public function test_sales_reset_releases_orphan_reservation_then_stock_sync_restores_the_authoritative_woo_total(): void
    {
        [$owner, $organization, $store, $showroom, $integration] = $this->wooContext(syncStock: true);
        $secondary = $this->createWarehouse($organization, 'SP', 'SP');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'RESET-STOCK', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $product = $variant->product()->firstOrFail();
        $catalogBefore = [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'variant_id' => $variant->getKey(),
            'variant_sku' => $variant->sku,
        ];
        $this->openStock($owner, $organization, $secondary, $variant, '9.0000');

        $customer = $this->createCustomer($organization, 'Reset stock customer');
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $secondary, ['quantity' => '1.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $reservationId = (int) $order->lines()->firstOrFail()->allocations()->value('inventory_reservation_id');
        $this->assertSame('9.0000', $this->balance($organization, $secondary, $variant)->on_hand);
        $this->assertSame('1.0000', $this->balance($organization, $secondary, $variant)->reserved);
        $this->assertSame('8.0000', $this->balance($organization, $secondary, $variant)->available);

        $plan = app(OrganizationDataResetPreviewService::class)
            ->preview($organization, OrganizationDataResetScope::SalesAndDocuments);
        $this->assertTrue($plan->isExecutable(), json_encode($plan->blockers));
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $this->assertDatabaseMissing('inventory_reservations', ['id' => $reservationId]);
        $this->assertSame(0, $organization->salesOrders()->count());
        $this->assertSame('9.0000', $this->balance($organization, $secondary, $variant)->on_hand);
        $this->assertSame('0.0000', $this->balance($organization, $secondary, $variant)->reserved);
        $this->assertSame('9.0000', $this->balance($organization, $secondary, $variant)->available);

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'RESET-STOCK', 'manage_stock' => true, 'stock_quantity' => 10]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(1, $run->stock_adjustments);
        $this->assertSame('1.0000', $this->balance($organization, $showroom, $variant)->on_hand);
        $this->assertSame('9.0000', $this->balance($organization, $secondary, $variant)->on_hand);
        $this->assertSame('10.0000', \App\Models\InventoryBalance::query()
            ->where('organization_id', $organization->id)
            ->where('product_variant_id', $variant->id)
            ->get()
            ->reduce(fn (string $total, $balance) => \App\Support\InventoryQuantity::add($total, $balance->on_hand), '0.0000'));
        $this->assertSame($catalogBefore, [
            'product_id' => $product->fresh()->getKey(),
            'product_name' => $product->fresh()->name,
            'variant_id' => $variant->fresh()->getKey(),
            'variant_sku' => $variant->fresh()->sku,
        ]);
    }

    public function test_stock_reconciliation_ignores_balances_from_other_organizations(): void
    {
        [$owner, $organization, , $syncWarehouse, $integration] = $this->wooContext(syncStock: true);

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'TENANT-STK', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);
        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();

        $otherOwner = User::factory()->create();
        $otherOrganization = $this->createOrganization($otherOwner);
        $otherWarehouse = $this->createWarehouse($otherOrganization, 'Autre dépôt', 'OTHER');
        $otherVariant = $this->createProduct($otherOrganization, 'Autre produit', 'TENANT-STK')->variants->first();
        $this->openStock($otherOwner, $otherOrganization, $otherWarehouse, $otherVariant, '99.0000');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'TENANT-STK', 'manage_stock' => true, 'stock_quantity' => 2]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(1, $run->stock_adjustments);
        $this->assertSame('2.0000', $this->balance($organization, $syncWarehouse, $variant)->on_hand);
        $this->assertSame('99.0000', $this->balance($otherOrganization, $otherWarehouse, $otherVariant)->on_hand);
    }
}
