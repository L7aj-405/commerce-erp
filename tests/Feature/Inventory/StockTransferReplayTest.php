<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\InventoryTestCase;

/** BUG-03: a retried / double-clicked transfer submit never moves stock twice. */
class StockTransferReplayTest extends InventoryTestCase
{
    public function test_the_same_operation_id_is_executed_once_and_replays_to_the_same_transfer(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Source');
        $destination = $this->createWarehouse($organization, 'Destination');
        $variant = $this->createProduct($organization, 'Replay Product', 'REPLAY')->variants->first();
        $this->openStock($owner, $organization, $source, $variant, '10.0000');
        $payload = [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'reason' => 'Reequilibrage',
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 4]],
        ];

        $first = $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload);
        $second = $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertSame('6.0000', $this->balance($organization, $source, $variant)->on_hand);
        $this->assertSame('4.0000', $this->balance($organization, $destination, $variant)->on_hand);
    }

    public function test_idempotency_is_durable_and_survives_a_cache_flush(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Source');
        $destination = $this->createWarehouse($organization, 'Destination');
        $variant = $this->createProduct($organization, 'Durable Product', 'DURABLE')->variants->first();
        $this->openStock($owner, $organization, $source, $variant, '10.0000');
        $operation = (string) Str::uuid();
        $payload = [
            'source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id, 'reason' => 'Durable',
            'client_operation_id' => $operation, 'lines' => [['product_variant_id' => $variant->id, 'quantity' => 3]],
        ];

        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('stock_transfers', ['organization_id' => $organization->id, 'client_operation_id' => $operation]);

        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertSame('7.0000', $this->balance($organization, $source, $variant)->on_hand);
    }

    public function test_reusing_an_operation_id_with_different_data_is_refused_and_moves_nothing(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Source');
        $destination = $this->createWarehouse($organization, 'Destination');
        $variant = $this->createProduct($organization, 'Mismatch Product', 'MISMATCH')->variants->first();
        $this->openStock($owner, $organization, $source, $variant, '10.0000');
        $operation = (string) Str::uuid();
        $payload = fn (int $quantity) => [
            'source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id, 'reason' => 'Same',
            'client_operation_id' => $operation, 'lines' => [['product_variant_id' => $variant->id, 'quantity' => $quantity]],
        ];

        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload(2))->assertRedirect();
        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload(5))->assertSessionHasErrors('client_operation_id');

        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertSame('8.0000', $this->balance($organization, $source, $variant)->on_hand);
    }

    public function test_a_failed_attempt_releases_the_operation_id_so_it_can_be_corrected_and_retried(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Source');
        $destination = $this->createWarehouse($organization, 'Destination');
        $variant = $this->createProduct($organization, 'Retry Product', 'RETRY')->variants->first();
        $this->openStock($owner, $organization, $source, $variant, '2.0000');
        $operation = (string) Str::uuid();
        $payload = fn (int $quantity) => [
            'source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id, 'reason' => 'Test',
            'client_operation_id' => $operation, 'lines' => [['product_variant_id' => $variant->id, 'quantity' => $quantity]],
        ];

        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload(9))->assertSessionHasErrors();
        $this->assertDatabaseCount('stock_transfers', 0);

        $this->actingAs($owner)->post(route('inventory.transfers.store'), $payload(2))->assertRedirect();
        $this->assertDatabaseCount('stock_transfers', 1);
    }
}
