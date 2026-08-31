<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryTestCase;

class StockTransferWorkflowTest extends InventoryTestCase
{
    public function test_stock_navigation_pages_are_available_to_an_authorized_user(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Shop Partner');
        $destination = $this->createWarehouse($organization, 'Showroom Guliz');

        $this->actingAs($owner)->get(route('inventory.warehouses.index'))->assertInertia(fn (Assert $page) => $page
            ->where('warehouses.0.name', $destination->name)
            ->where('warehouses.1.name', $source->name));

        $this->actingAs($owner)->get(route('inventory.transfers.create'))->assertInertia(fn (Assert $page) => $page
            ->has('warehouses', 2)
            ->where('warehouses.0.name', $destination->name));
    }

    public function test_multi_product_transfer_is_atomic_and_preserves_total_stock(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Shop Partner');
        $destination = $this->createWarehouse($organization, 'Showroom Guliz');
        $productA = $this->createProduct($organization, 'Shure SM58', 'SM58')->variants->first();
        $productB = $this->createProduct($organization, 'Bose S1 Pro', 'BOSE')->variants->first();
        $this->openStock($owner, $organization, $source, $productA, '8.0000');
        $this->openStock($owner, $organization, $destination, $productA, '2.0000');
        $this->openStock($owner, $organization, $source, $productB, '5.0000');

        $this->actingAs($owner)->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'reason' => 'Reequilibrage',
            'lines' => [
                ['product_variant_id' => $productA->id, 'quantity' => 3],
                ['product_variant_id' => $productB->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $this->assertSame('5.0000', $this->balance($organization, $source, $productA)->on_hand);
        $this->assertSame('5.0000', $this->balance($organization, $destination, $productA)->on_hand);
        $this->assertSame('3.0000', $this->balance($organization, $source, $productB)->on_hand);
        $this->assertSame('2.0000', $this->balance($organization, $destination, $productB)->on_hand);
        $this->assertDatabaseHas('stock_transfers', [
            'organization_id' => $organization->id,
            'product_count' => 2,
            'unit_count' => '5.0000',
        ]);
        $this->assertDatabaseCount('stock_transfer_lines', 2);
    }

    public function test_reserved_stock_cannot_be_transferred_beyond_available_quantity(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Shop Partner');
        $destination = $this->createWarehouse($organization, 'Showroom Guliz');
        $variant = $this->createProduct($organization, 'Shure SM58', 'SM58')->variants->first();
        $this->openStock($owner, $organization, $source, $variant, '10.0000');
        $this->reserve($owner, $organization, $source, $variant, '4.0000');

        $this->actingAs($owner)->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'reason' => 'Attack',
            'lines' => [
                ['product_variant_id' => $variant->id, 'quantity' => 7],
            ],
        ])->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame('10.0000', $this->balance($organization, $source, $variant)->on_hand);
        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_invalid_multi_line_transfer_rolls_back_everything(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Shop Partner');
        $destination = $this->createWarehouse($organization, 'Showroom Guliz');
        $productA = $this->createProduct($organization, 'Shure SM58', 'SM58')->variants->first();
        $productB = $this->createProduct($organization, 'Bose S1 Pro', 'BOSE')->variants->first();
        $this->openStock($owner, $organization, $source, $productA, '8.0000');
        $this->openStock($owner, $organization, $source, $productB, '1.0000');

        $this->actingAs($owner)->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'reason' => 'Atomicite',
            'lines' => [
                ['product_variant_id' => $productA->id, 'quantity' => 2],
                ['product_variant_id' => $productB->id, 'quantity' => 4],
            ],
        ])->assertSessionHasErrors('lines.1.quantity');

        $this->assertSame('8.0000', $this->balance($organization, $source, $productA)->on_hand);
        $this->assertSame('1.0000', $this->balance($organization, $source, $productB)->on_hand);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('stock_transfer_lines', 0);
    }

    public function test_foreign_transfer_ids_are_rejected_by_scoped_validation(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $source = $this->createWarehouse($organizationA, 'A source');
        $destination = $this->createWarehouse($organizationA, 'A destination');
        $foreignWarehouse = $this->createWarehouse($organizationB, 'B warehouse');
        $variant = $this->createProduct($organizationA, 'A product', 'A-PROD')->variants->first();
        $foreignVariant = $this->createProduct($organizationB, 'B product', 'B-PROD')->variants->first();
        $this->openStock($ownerA, $organizationA, $source, $variant, '4.0000');

        $this->actingAs($ownerA)->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $foreignWarehouse->id,
            'destination_warehouse_id' => $destination->id,
            'reason' => 'Attack',
            'lines' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('source_warehouse_id');

        $this->actingAs($ownerA)->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'reason' => 'Attack',
            'lines' => [
                ['product_variant_id' => $foreignVariant->id, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('lines.0.product_variant_id');
    }
}
