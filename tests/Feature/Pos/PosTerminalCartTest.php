<?php

namespace Tests\Feature\Pos;

use App\Http\Controllers\PosController;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

/**
 * Each POS terminal (browser/device, identified by an encrypted cookie) has
 * its own active cart inside a Store. A terminal is never a tenancy source.
 */
class PosTerminalCartTest extends PosTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization, 'Showroom', 'SHW');
        $this->activate($this->owner, $this->organization, $this->store);
    }

    public function test_two_terminals_of_the_same_user_in_the_same_store_get_different_carts(): void
    {
        $a = $this->openCart($this->owner, $terminalA = (string) Str::uuid());
        $b = $this->openCart($this->owner, (string) Str::uuid());

        $this->assertNotSame($a, $b);
        $this->assertSame($terminalA, SalesOrder::query()->find($a)->pos_terminal_id);
        $this->assertSame((int) $this->store->id, (int) SalesOrder::query()->find($b)->store_id);
    }

    public function test_two_users_of_the_same_store_get_different_carts(): void
    {
        $cashier = User::factory()->create();
        $this->addOrganizationMember($this->organization, $cashier, ['pos.access', 'sales_orders.create', 'sales_orders.update', 'products.view', 'inventory.view']);
        $this->addStoreMember($this->store, $cashier);
        $this->activate($cashier, $this->organization, $this->store);

        $this->assertNotSame($this->openCart($this->owner, (string) Str::uuid()), $this->openCart($cashier, (string) Str::uuid()));
    }

    public function test_the_same_terminal_finds_its_cart_again_after_a_refresh(): void
    {
        $terminal = (string) Str::uuid();
        $cart = $this->openCart($this->owner, $terminal);

        $this->assertSame($cart, $this->openCart($this->owner, $terminal));
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $terminal)->actingAs($this->owner)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('activeSale.id', $cart));
    }

    public function test_a_browser_without_a_terminal_gets_one_from_the_pos_page(): void
    {
        $this->actingAs($this->owner)->get(route('pos.index'))->assertOk()->assertCookie(PosController::TERMINAL_COOKIE);
    }

    public function test_a_cart_from_before_terminals_is_adopted_once_and_never_shared(): void
    {
        $legacy = $this->createPosDraft($this->owner, $this->organization, $this->store, $this->warehouse); // no terminal

        $first = $this->openCart($this->owner, $terminalA = (string) Str::uuid());
        $second = $this->openCart($this->owner, (string) Str::uuid());

        $this->assertSame($legacy->id, $first);
        $this->assertSame($terminalA, $legacy->fresh()->pos_terminal_id);
        $this->assertNotSame($legacy->id, $second);
    }

    public function test_a_held_cart_stays_resumable_by_another_terminal_which_then_owns_it(): void
    {
        $terminalA = (string) Str::uuid();
        $terminalB = (string) Str::uuid();
        $cart = $this->openCart($this->owner, $terminalA);
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $terminalA)->actingAs($this->owner)
            ->postJson(route('pos.drafts.hold', $cart))->assertOk();

        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $terminalB)->actingAs($this->owner)
            ->postJson(route('pos.drafts.resume', $cart))->assertOk()->assertJsonPath('active_sale.id', $cart);

        $this->assertSame($terminalB, SalesOrder::query()->find($cart)->pos_terminal_id);
        $this->assertSame($cart, $this->openCart($this->owner, $terminalB));
    }

    public function test_a_terminal_never_sees_a_cart_of_another_store_or_organization(): void
    {
        $terminal = (string) Str::uuid();
        $cart = $this->openCart($this->owner, $terminal);

        // Same terminal, another Store of the organisation: its own cart.
        $otherStore = $this->createStore($this->organization, $this->owner, 'Second');
        $this->activate($this->owner, $this->organization, $otherStore);
        $this->assertNotSame($cart, $this->openCart($this->owner, $terminal));
        $this->assertSame((int) $otherStore->id, (int) SalesOrder::query()->latest('id')->first()->store_id);

        // Another organisation replaying the same terminal id sees nothing of ours.
        $stranger = User::factory()->create();
        $foreign = $this->createOrganization($stranger, 'Foreign');
        $foreignStore = $this->createStore($foreign, $stranger);
        $this->createWarehouse($foreign, 'Main', 'MAIN');
        $this->activate($stranger, $foreign, $foreignStore);
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $terminal)->actingAs($stranger)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('activeSale', null));
    }

    private function openCart(User $user, string $terminal): int
    {
        $warehouseId = Warehouse::query()->where('organization_id', $user->active_organization_id)->value('id');

        return $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $terminal)->actingAs($user)
            ->postJson(route('pos.drafts.store'), ['warehouse_id' => $warehouseId])
            ->assertOk()->json('active_sale.id');
    }
}
