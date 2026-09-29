<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
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
        $this->openStock($owner, $organization, $otherWarehouse, $variant, '8.0000');

        // Woo reports 15 total. ERP already has 10 across warehouses, so only +5
        // is added to the configured Woo sync warehouse. The sync warehouse must
        // not be overwritten to 15.
        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG', 'manage_stock' => true, 'stock_quantity' => 15]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(1, $run->stock_adjustments);
        $this->assertSame('7.0000', $this->balance($organization, $syncWarehouse, $variant)->on_hand);
        $this->assertSame('8.0000', $this->balance($organization, $otherWarehouse, $variant)->on_hand);

        $movement = InventoryMovement::query()
            ->where('organization_id', $organization->getKey())
            ->where('warehouse_id', $syncWarehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('movement_type', InventoryMovementType::AdjustmentIn)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('5.0000', $movement->quantity);
        $this->assertSame('2.0000', $movement->quantity_before);
        $this->assertSame('7.0000', $movement->quantity_after);
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

    public function test_negative_aggregate_delta_is_blocked_when_sync_warehouse_cannot_absorb_it(): void
    {
        [$owner, $organization, , $syncWarehouse, $integration] = $this->wooContext(syncStock: true);
        $otherWarehouse = $this->createWarehouse($organization, 'Dépôt principal', 'MAIN');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-BLOCK', 'manage_stock' => false, 'stock_quantity' => null]),
        ]);
        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->openStock($owner, $organization, $syncWarehouse, $variant, '1.0000');
        $this->openStock($owner, $organization, $otherWarehouse, $variant, '7.0000');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'STK-AGG-BLOCK', 'manage_stock' => true, 'stock_quantity' => 3]),
        ]);
        $run = $this->runSync($integration, $owner);

        $this->assertSame(0, $run->stock_adjustments);
        $this->assertSame('1.0000', $this->balance($organization, $syncWarehouse, $variant)->on_hand);
        $this->assertSame('7.0000', $this->balance($organization, $otherWarehouse, $variant)->on_hand);
        $this->assertSame(2, InventoryMovement::where('organization_id', $organization->getKey())
            ->where('product_variant_id', $variant->getKey())->count());
        $this->assertNotEmpty($run->errors);
        $this->assertStringContainsString('écart négatif dépasse le stock physique disponible', $run->errors[0]['message']);
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
