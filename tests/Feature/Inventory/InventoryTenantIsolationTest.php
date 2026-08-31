<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryTestCase;

class InventoryTenantIsolationTest extends InventoryTestCase
{
    public function test_stock_and_movement_pages_return_only_active_organization_records(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'Organization A');
        $warehouseA = $this->createWarehouse($organizationA, 'Warehouse A');
        $variantA = $this->createProduct($organizationA, 'Product A', 'SKU-A')->variants->first();
        $this->openStock($owner, $organizationA, $warehouseA, $variantA);

        $organizationB = $this->createOrganization($owner, 'Organization B');
        $warehouseB = $this->createWarehouse($organizationB, 'Warehouse B');
        $variantB = $this->createProduct($organizationB, 'Product B', 'SKU-B')->variants->first();
        $this->activate($owner, $organizationB);
        $this->openStock($owner, $organizationB, $warehouseB, $variantB);
        $this->activate($owner, $organizationA);

        $this->actingAs($owner)->get(route('inventory.stock.index'))->assertInertia(fn (Assert $page) => $page
            ->has('balances.data', 1)
            ->where('balances.data.0.sku', 'SKU-A')
            ->where('balances.data.0.product.name', 'Product A'));
        $this->actingAs($owner)->get(route('inventory.movements.index'))->assertInertia(fn (Assert $page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.product_variant.sku', 'SKU-A')
            ->where('movements.data.0.warehouse.name', 'Warehouse A'));
    }

    public function test_foreign_warehouse_filter_is_rejected_without_metadata_leakage(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'A');
        $organizationB = $this->createOrganization($owner, 'B');
        $warehouseB = $this->createWarehouse($organizationB, 'Secret Foreign Warehouse');
        $this->activate($owner, $organizationA);

        $response = $this->actingAs($owner)->getJson(route('inventory.stock.index', ['warehouse' => $warehouseB->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse');
        $this->assertStringNotContainsString('Secret Foreign Warehouse', $response->getContent());
    }
}
