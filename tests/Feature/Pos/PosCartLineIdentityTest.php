<?php

namespace Tests\Feature\Pos;

use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Support\PosTestCase;

/**
 * A cart line is the customer's commercial demand ("N × article"); where the
 * units come from is its inventory allocation. The supplying location never
 * splits a line, and stock-source wording comes from real free stock.
 */
class PosCartLineIdentityTest extends PosTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $showroom;

    private Warehouse $depot;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->showroom = $this->createWarehouse($this->organization, 'Showroom', 'SHW');
        $this->depot = $this->createWarehouse($this->organization, 'Entrepôt SP', 'ESP');
        $this->variant = $this->createProduct($this->organization, 'Product X', 'PX', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($this->owner, $this->organization, $this->store);
    }

    public function test_the_same_variant_added_from_two_sessions_with_different_locations_is_one_line(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '1.0000');

        // Two tabs of the SAME terminal (here: no terminal cookie, the legacy
        // shared cart) work on one cart with different selectors; tab B adds the
        // same article. Different terminals get different carts (PosTerminalCartTest).
        $cartId = $this->openCart($this->depot);
        $this->add($cartId, $this->depot);
        $this->assertSame($cartId, $this->openCart($this->showroom));
        $state = $this->add($cartId, $this->showroom);

        $this->assertCount(1, $state['lines']);
        $this->assertSame('2.0000', $state['lines'][0]['quantity']);
        $this->assertSame(1, SalesOrderLine::query()->count());
        // The cart keeps ONE anchor (the location it was opened on), whatever
        // the session that adds — and the one physical unit is counted once.
        $this->assertSame('ESP', $state['warehouse']['code']);
        $this->assertSame(['here' => '0.0000', 'elsewhere' => '1.0000', 'missing' => '1.0000'], $this->source($state));
        $this->assertSame('to_source', $state['lines'][0]['sourcing']['state']);
        $this->assertSame('1.0000', $state['lines'][0]['sourcing']['shortage']);
    }

    public function test_repeated_adds_increment_one_line_and_never_create_duplicates(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '5.0000');
        $cartId = $this->openCart($this->showroom);

        foreach (range(1, 3) as $ignored) {
            $state = $this->add($cartId, $this->showroom);
        }

        $this->assertCount(1, $state['lines']);
        $this->assertSame('3.0000', $state['lines'][0]['quantity']);
        $this->assertSame(1, SalesOrderLine::query()->where('sales_order_id', $cartId)->count());
    }

    public function test_local_stock_is_never_reported_as_coming_from_another_location(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '1.0000');
        $cartId = $this->openCart($this->showroom);

        $state = $this->add($cartId, $this->showroom);

        $this->assertSame(['here' => '1.0000', 'elsewhere' => '0.0000', 'missing' => '0.0000'], $this->source($state));
        $this->assertSame('0.0000', $state['lines'][0]['remote_required']);
        $this->assertFalse($state['requires_replenishment']);
    }

    public function test_one_line_is_supplied_by_several_locations(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '1.0000');
        $this->openStock($this->owner, $this->organization, $this->depot, $this->variant, '1.0000');
        $cartId = $this->openCart($this->showroom);

        $this->add($cartId, $this->showroom);
        $state = $this->add($cartId, $this->showroom);

        $this->assertCount(1, $state['lines']);
        $this->assertSame(['here' => '1.0000', 'elsewhere' => '1.0000', 'missing' => '0.0000'], $this->source($state));
        $allocations = SalesOrderInventoryAllocation::query()->with('warehouse')->get()
            ->mapWithKeys(fn ($a) => [$a->warehouse->code => $a->quantity])->sortKeys()->all();
        $this->assertSame(['ESP' => '1.0000', 'SHW' => '1.0000'], $allocations);
        $this->assertSame('1.0000', $state['lines'][0]['remote_required']);
    }

    public function test_an_organization_shortage_shows_the_exact_missing_quantity(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '1.0000');
        $this->openStock($this->owner, $this->organization, $this->depot, $this->variant, '1.0000');
        $cartId = $this->openCart($this->showroom);

        $state = $this->add($cartId, $this->showroom, '3');

        $this->assertSame(['here' => '1.0000', 'elsewhere' => '1.0000', 'missing' => '1.0000'], $this->source($state));
        $this->assertSame('to_source', $state['lines'][0]['sourcing']['state']);
        $this->assertSame('1.0000', $state['lines'][0]['sourcing']['shortage']);
    }

    public function test_different_commercial_conditions_keep_separate_lines(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '10.0000');
        $cartId = $this->openCart($this->showroom);

        $this->add($cartId, $this->showroom);
        $this->add($cartId, $this->showroom, '1', ['discount_type' => 'percentage', 'discount_value' => '10']);
        $state = $this->add($cartId, $this->showroom, '1', ['unit_price_excl_tax' => '90.0000']);
        $this->assertCount(3, $state['lines']);

        // A plain add joins the plain line, never the discounted / overridden ones.
        $state = $this->add($cartId, $this->showroom);
        $this->assertSame(['2.0000', '1.0000', '1.0000'], array_column($state['lines'], 'quantity'));
    }

    public function test_two_lines_of_one_article_never_claim_the_same_unit(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '1.0000');
        $cartId = $this->openCart($this->showroom);

        $this->add($cartId, $this->showroom);
        $state = $this->add($cartId, $this->showroom, '1', ['discount_type' => 'fixed', 'discount_value' => '5']);

        $this->assertSame(['here' => '1.0000', 'elsewhere' => '0.0000', 'missing' => '0.0000'], $this->source($state, 0));
        $this->assertSame(['here' => '0.0000', 'elsewhere' => '0.0000', 'missing' => '1.0000'], $this->source($state, 1));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function openCart(Warehouse $selected): int
    {
        return $this->actingAs($this->owner)->postJson(route('pos.drafts.store'), ['warehouse_id' => $selected->id])
            ->assertOk()->json('active_sale.id');
    }

    /** @return array<string, mixed> */
    private function add(int $cartId, Warehouse $selected, string $quantity = '1', array $overrides = []): array
    {
        return $this->actingAs($this->owner)->postJson(route('pos.drafts.lines.store', $cartId), array_replace([
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'warehouse_id' => $selected->id,
            'quantity' => $quantity, 'discount_type' => 'none',
        ], $overrides))->assertOk()->json('active_sale');
    }

    /** @return array{here: string, elsewhere: string, missing: string} */
    private function source(array $state, int $index = 0): array
    {
        return array_intersect_key($state['lines'][$index]['stock_source'], array_flip(['here', 'elsewhere', 'missing']));
    }
}
