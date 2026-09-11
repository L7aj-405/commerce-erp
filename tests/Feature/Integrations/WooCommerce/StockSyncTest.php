<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\WooCommerceSyncRun;
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
}
