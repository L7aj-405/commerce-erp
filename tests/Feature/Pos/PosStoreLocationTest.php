<?php

namespace Tests\Feature\Pos;

use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

/**
 * Store (commercial point of sale) ↔ inventory location (physical stock).
 * One ledger: stock stays on warehouses; a Store only names its own locations
 * and its default POS location.
 */
class PosStoreLocationTest extends PosTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $storeA;

    private Store $storeB;

    private Warehouse $showroomA;

    private Warehouse $showroomB;

    private Warehouse $depot;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->storeA = $this->createStore($this->organization, $this->owner, 'Marrakech Showroom');
        $this->storeB = $this->createStore($this->organization, $this->owner, 'Casablanca Store');
        $this->showroomA = $this->createWarehouse($this->organization, 'Showroom', 'MAIN');
        $this->showroomB = $this->createWarehouse($this->organization, 'Casa', 'CASA');
        $this->depot = $this->createWarehouse($this->organization, 'Entrepôt SP', 'ESP');
        $this->variant = $this->createProduct($this->organization, 'Product X', 'PX', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($this->owner, $this->organization, $this->storeA);
    }

    public function test_an_unconfigured_store_keeps_every_active_location(): void
    {
        $this->pos()->assertInertia(fn (Assert $page) => $page->has('warehouses', 3));
    }

    public function test_a_configured_store_sells_from_its_own_locations_and_direct_sales_depots_only(): void
    {
        $this->link();
        $this->pos()->assertInertia(fn (Assert $page) => $page
            ->where('warehouses', fn ($rows) => collect($rows)->pluck('code')->all() === ['MAIN'])
            ->where('defaultWarehouseId', $this->showroomA->id)
            ->where('storeDefaultWarehouseId', $this->showroomA->id)
            ->where('warehouses.0.kind', 'store'));

        // The depot becomes eligible only when explicitly opened to direct sales.
        $this->depot->forceFill(['allow_direct_sales' => true])->save();
        $this->pos()->assertInertia(fn (Assert $page) => $page
            ->where('warehouses', fn ($rows) => collect($rows)->pluck('code')->sort()->values()->all() === ['ESP', 'MAIN'])
            ->where('warehouses', fn ($rows) => collect($rows)->firstWhere('code', 'ESP')['kind'] === 'depot'));

        // Store B's location is never a Store A selling location.
        $this->actingAs($this->owner)->postJson(route('pos.drafts.store'), ['warehouse_id' => $this->showroomB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    }

    public function test_the_selling_store_stays_while_stock_comes_from_a_depot(): void
    {
        $this->link();
        $this->openStock($this->owner, $this->organization, $this->showroomA, $this->variant, '1.0000');
        $this->openStock($this->owner, $this->organization, $this->depot, $this->variant, '2.0000');

        $cartId = $this->actingAs($this->owner)->postJson(route('pos.drafts.store'), ['warehouse_id' => $this->showroomA->id])->json('active_sale.id');
        $state = $this->actingAs($this->owner)->postJson(route('pos.drafts.lines.store', $cartId), [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'quantity' => '3', 'discount_type' => 'none',
        ])->assertOk()->json('active_sale');

        $this->assertSame((int) $this->storeA->id, (int) SalesOrder::query()->find($cartId)->store_id);
        $this->assertSame(['1.0000', '2.0000', '0.0000'], [$state['lines'][0]['stock_source']['here'], $state['lines'][0]['stock_source']['elsewhere'], $state['lines'][0]['stock_source']['missing']]);
        $this->assertSame(['ESP' => '2.0000', 'MAIN' => '1.0000'], SalesOrderInventoryAllocation::query()->with('warehouse')->get()
            ->mapWithKeys(fn ($a) => [$a->warehouse->code => $a->quantity])->sortKeys()->all());
    }

    public function test_store_administration_lists_edits_and_guards_the_default_location(): void
    {
        $this->link();
        $this->actingAs($this->owner)->get(route('stores.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Stores/Index')
            ->has('stores', 2)
            ->where('stores', fn ($rows) => collect($rows)->firstWhere('id', $this->storeA->id)['locations'][0]['code'] === 'MAIN'));

        $this->actingAs($this->owner)->patch(route('stores.update', $this->storeA), [
            'name' => 'Marrakech Showroom', 'code' => $this->storeA->code, 'default_warehouse_id' => $this->showroomB->id,
        ])->assertSessionHasErrors('default_warehouse_id');

        $this->actingAs($this->owner)->patch(route('stores.update', $this->storeA), [
            'name' => 'Marrakech Centre', 'code' => $this->storeA->code, 'default_warehouse_id' => $this->depot->id,
        ])->assertSessionHasNoErrors();
        // Activation is its own explicit endpoint (the generic update ignores status).
        $this->actingAs($this->owner)->patch(route('stores.status', $this->storeA), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame(['Marrakech Centre', $this->depot->id, 'inactive'], array_values($this->storeA->fresh()->only(['name', 'default_warehouse_id', 'status'])));
    }

    public function test_store_administration_respects_permissions(): void
    {
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $viewer, ['stores.view']);
        $this->addStoreMember($this->storeA, $viewer);
        $this->activate($viewer, $this->organization, $this->storeA);

        $this->actingAs($viewer)->get(route('stores.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false)->where('can.create', false));
        $this->actingAs($viewer)->patch(route('stores.update', $this->storeA), ['name' => 'X', 'code' => 'X'])->assertForbidden();
    }

    public function test_warehouse_administration_links_locations_to_stores_without_moving_stock(): void
    {
        $this->openStock($this->owner, $this->organization, $this->depot, $this->variant, '4.0000');

        $this->actingAs($this->owner)->patch(route('inventory.warehouses.update', $this->depot), [
            'name' => 'Entrepôt SP', 'code' => 'ESP', 'status' => 'active', 'store_id' => null, 'allow_direct_sales' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->depot->fresh()->allow_direct_sales);

        // Linked to a Store: a store location, no longer a "direct sales depot".
        $this->actingAs($this->owner)->patch(route('inventory.warehouses.update', $this->depot), [
            'name' => 'Entrepôt SP', 'code' => 'ESP', 'status' => 'active', 'store_id' => $this->storeB->id, 'allow_direct_sales' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame([(int) $this->storeB->id, false], [(int) $this->depot->fresh()->store_id, $this->depot->fresh()->allow_direct_sales]);
        $this->assertSame('4.0000', $this->balance($this->organization, $this->depot, $this->variant)->on_hand, 'stock never moves with the association');

        // A location still used as a Store's default POS location cannot leave it.
        $this->storeB->forceFill(['default_warehouse_id' => $this->depot->id])->save();
        $this->actingAs($this->owner)->patch(route('inventory.warehouses.update', $this->depot), [
            'name' => 'Entrepôt SP', 'code' => 'ESP', 'status' => 'active', 'store_id' => null,
        ])->assertSessionHasErrors('store_id');

        // Another organisation's Store is never accepted.
        $stranger = User::factory()->create();
        $foreignStore = $this->createStore($this->createOrganization($stranger, 'Foreign'), $stranger);
        $this->actingAs($this->owner)->patch(route('inventory.warehouses.update', $this->showroomA), [
            'name' => 'Showroom', 'code' => 'MAIN', 'status' => 'active', 'store_id' => $foreignStore->id,
        ])->assertSessionHasErrors('store_id');
    }

    /** Store A owns MAIN (its default); Store B owns CASA; ESP is a storage depot. */
    private function link(): void
    {
        $this->showroomA->forceFill(['store_id' => $this->storeA->id])->save();
        $this->showroomB->forceFill(['store_id' => $this->storeB->id])->save();
        $this->storeA->forceFill(['default_warehouse_id' => $this->showroomA->id])->save();
    }

    private function pos(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->get(route('pos.index'))->assertOk();
    }
}
