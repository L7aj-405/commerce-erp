<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class PosHoldSaleTest extends PosTestCase
{
    public function test_active_pos_sale_can_be_held_and_new_sale_can_be_started(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '2.0000']);

        $response = $this->actingAs($owner)->postJson(route('pos.drafts.hold', $draft), [
            'start_new_sale' => true,
            'warehouse_id' => $warehouse->id,
        ])->assertOk();

        $draft->refresh();
        $this->assertNotNull($draft->pos_held_at);
        $response->assertJsonPath('held_sales.0.id', $draft->id);
        $this->assertSame(2, \App\Models\SalesOrder::query()->where('source', 'pos')->where('status', 'draft')->count());
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_held_sale_can_be_resumed_with_lines_and_quantities_preserved(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '3.0000']);
        $draft->pos_held_at = now();
        $draft->save();

        $this->actingAs($owner)->postJson(route('pos.drafts.resume', $draft))->assertOk()
            ->assertJsonPath('active_sale.id', $draft->id)
            ->assertJsonPath('active_sale.lines.0.quantity', '3.0000')
            ->assertJsonPath('active_sale.lines.0.product_variant_id', $variant->id);
    }

    public function test_resumed_sale_rechecks_stock_availability(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context('3.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $line = $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '3.0000']);
        $draft->pos_held_at = now();
        $draft->save();

        $balance = $this->balance($organization, $warehouse, $variant);
        $balance->on_hand = '2.0000';
        $balance->save();

        $this->actingAs($owner)->postJson(route('pos.drafts.resume', $draft))->assertOk()
            ->assertJsonPath('active_sale.availability_warnings.0.line_id', $line->id)
            ->assertJsonPath('active_sale.availability_warnings.0.available', '2.0000')
            ->assertJsonPath('active_sale.lines.0.insufficient', true);
    }

    public function test_held_sale_can_be_cancelled_without_becoming_paid_or_fulfilled(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse);
        $draft->pos_held_at = now();
        $draft->save();

        $this->actingAs($owner)->deleteJson(route('pos.drafts.destroy', $draft))->assertOk();

        $this->assertDatabaseHas('sales_orders', [
            'id' => $draft->id,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
        ]);
    }

    public function test_held_sale_is_store_scoped_and_hidden_from_foreign_tenant(): void
    {
        [$ownerA, $organizationA, $storeA, $warehouseA] = $this->context();
        $draft = $this->createPosDraft($ownerA, $organizationA, $storeA, $warehouseA);
        $draft->pos_held_at = now();
        $draft->save();

        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $storeB = $this->createStore($organizationB, $ownerB);
        $this->createWarehouse($organizationB, 'Other');
        $this->activate($ownerB, $organizationB, $storeB);

        $this->actingAs($ownerB)->postJson(route('pos.drafts.resume', $draft))->assertNotFound();
        $this->actingAs($ownerB)->deleteJson(route('pos.drafts.destroy', $draft))->assertNotFound();
    }

    public function test_pos_index_includes_active_sale_and_held_sales_payload(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context();
        $active = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $active, $variant, $warehouse);
        $held = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $held->pos_held_at = now();
        $held->save();

        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index')
            ->where('activeSale.id', $active->id)
            ->where('activeSale.warehouse.id', $warehouse->id)
            ->where('heldSales.0.id', $held->id));
    }

    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\Warehouse, \App\Models\ProductVariant} */
    private function context(string $stock = '5.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization, 'Showroom');
        $variant = $this->createProduct($organization, 'Held Product', 'HOLD-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
