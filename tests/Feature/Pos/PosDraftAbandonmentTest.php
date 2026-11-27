<?php

namespace Tests\Feature\Pos;

use App\Http\Controllers\PosController;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\PosDraftLifecycle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

/**
 * Sprint 0 final: event-driven abandonment of EMPTY POS carts, inactivity ATTENTION (never deletion)
 * for carts with products, explicit cancel + safe delete, and the gated scheduled fallback.
 */
class PosDraftAbandonmentTest extends PosTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    private string $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization, 'Showroom', 'SHW');
        $this->variant = $this->createProduct($this->organization, 'Abandon Product', 'ABD-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->variant, '10.0000');
        $this->terminal = (string) Str::uuid();
    }

    // --- 1/2/3. empty carts ------------------------------------------------------------------

    public function test_abandoning_after_removing_the_last_item_deletes_the_empty_draft_with_an_audit_trail(): void
    {
        $draft = $this->openCart();
        $line = $this->addLine($draft);
        $this->removeLine($draft, $line);

        $this->abandon($draft)->assertOk()->assertJsonPath('outcome', PosDraftLifecycle::OUTCOME_DISCARDED);

        $this->assertNull(SalesOrder::query()->find($draft));
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.pos_draft_discarded', 'auditable_id' => $draft]);
    }

    public function test_duplicate_abandon_requests_are_idempotent(): void
    {
        $draft = $this->openCart();

        $this->abandon($draft)->assertOk()->assertJsonPath('outcome', PosDraftLifecycle::OUTCOME_DISCARDED);
        // A repeated / late request finds nothing and changes nothing.
        $this->abandon($draft)->assertNotFound();
        $this->abandon($draft)->assertNotFound();

        $this->assertNull(SalesOrder::query()->find($draft));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'sales_order.pos_draft_discarded')->count());
    }

    public function test_reloading_the_pos_removes_an_orphaned_empty_cart_but_never_a_fresh_one(): void
    {
        $fresh = $this->openCart();
        $this->actingPos()->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page->where('activeSale.id', $fresh));
        $this->assertNotNull(SalesOrder::query()->find($fresh), 'Within the grace window an empty cart is left alone (active editing).');

        $this->setActivity($fresh, now()->subMinutes(2));
        $this->actingPos()->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page->where('activeSale', null));

        $this->assertNull(SalesOrder::query()->find($fresh));
    }

    public function test_switching_to_another_cart_removes_the_empty_one_and_parks_a_cart_with_products(): void
    {
        $empty = $this->openCart();
        $held = $this->heldDraftWithLine();

        $this->actingPos()->postJson(route('pos.drafts.resume', $held))->assertOk()->assertJsonPath('active_sale.id', $held);
        $this->assertNull(SalesOrder::query()->find($empty), 'The abandoned empty cart is gone.');

        // Now the active cart HAS products: switching away parks it instead of deleting it.
        $other = $this->heldDraftWithLine();
        $this->actingPos()->postJson(route('pos.drafts.resume', $other))->assertOk();
        $parked = SalesOrder::query()->findOrFail($held);
        $this->assertNotNull($parked->pos_held_at);
        $this->assertSame(1, $parked->lines()->count());
    }

    // --- 4/9. carts with products are never deleted ------------------------------------------------

    public function test_a_cart_with_products_survives_abandon_sweep_and_scheduled_cleanup(): void
    {
        $draft = $this->openCart();
        $this->addLine($draft);
        $this->setActivity($draft, now()->subDays(5));

        $this->abandon($draft)->assertJsonPath('outcome', PosDraftLifecycle::OUTCOME_SKIPPED_ACTIVE);
        $this->actingPos()->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page->where('activeSale.id', $draft));
        $this->assertSame(0, Artisan::call('pos:cleanup-drafts', ['--execute' => true]));

        $this->assertNotNull(SalesOrder::query()->find($draft));
        $this->assertSame(1, SalesOrder::query()->findOrFail($draft)->lines()->count());
    }

    public function test_a_zero_total_cart_with_a_free_product_is_not_treated_as_empty(): void
    {
        $free = $this->createProduct($this->organization, 'Free Gift', 'FREE-1', ['default_sale_price' => '0.0000'])->variants->first();
        $this->openStock($this->owner, $this->organization, $this->warehouse, $free, '5.0000');
        $draft = $this->openCart();
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)->actingAs($this->owner)
            ->postJson(route('pos.drafts.lines.store', $draft), $this->posCatalogLine($free, ['quantity' => '1', 'warehouse_id' => $this->warehouse->id]))->assertOk();
        $this->setActivity($draft, now()->subDays(2));

        $this->abandon($draft)->assertJsonPath('outcome', PosDraftLifecycle::OUTCOME_SKIPPED_ACTIVE);
        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);

        $this->assertNotNull(SalesOrder::query()->find($draft));
    }

    public function test_a_stale_cleanup_loses_to_a_concurrent_edit(): void
    {
        $draft = $this->openCart();
        $stale = SalesOrder::query()->findOrFail($draft); // the cleaner read an empty cart...
        $this->addLine($draft);                            // ...then another request added a product

        $result = app(PosDraftLifecycle::class)->discard($this->owner, $stale, PosDraftLifecycle::REASON_ABANDONED);

        $this->assertSame(PosDraftLifecycle::OUTCOME_SKIPPED_ACTIVE, $result['outcome']);
        $this->assertNotNull(SalesOrder::query()->find($draft));
        $this->assertSame(1, SalesOrder::query()->findOrFail($draft)->lines()->count());
    }

    public function test_the_unattended_scheduler_path_refuses_any_cart_with_products_even_if_forced(): void
    {
        $draft = $this->heldDraftWithLine();
        $this->setActivity($draft, now()->subYear());

        foreach ([PosDraftLifecycle::REASON_EMPTY, PosDraftLifecycle::REASON_ABANDONED, PosDraftLifecycle::REASON_MANUAL] as $reason) {
            $result = app(PosDraftLifecycle::class)->discard(null, SalesOrder::query()->findOrFail($draft), $reason, true);
            $this->assertSame(PosDraftLifecycle::OUTCOME_SKIPPED_ACTIVE, $result['outcome'], $reason);
        }
        $this->assertNotNull(SalesOrder::query()->find($draft));
    }

    // --- 5. inactivity attention ---------------------------------------------------------------------

    public function test_a_cart_with_products_idle_over_24h_is_flagged_everywhere_but_not_before(): void
    {
        $recent = $this->heldDraftWithLine();
        $this->setActivity($recent, now()->subHours(23));
        $idle = $this->heldDraftWithLine();
        $this->setActivity($idle, now()->subHours(25));

        $this->actingAs($this->owner)->get('/sales/orders')->assertInertia(fn (Assert $page) => $page
            ->where('summary.pos_inactifs', 1)
            ->where('summary.brouillons', 2)
            ->where('orders.data', fn ($rows) => collect($rows)->firstWhere('id', $idle)['pos_attention'] === true
                && collect($rows)->firstWhere('id', $recent)['pos_attention'] === false));

        $this->actingAs($this->owner)->get('/sales/orders?attention=1')->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.id', $idle));

        $this->actingAs($this->owner)->get("/sales/orders/{$idle}")->assertInertia(fn (Assert $page) => $page
            ->where('posDraft.attention', true)->where('posDraft.lines', 1));
        $this->actingAs($this->owner)->get("/sales/orders/{$recent}")->assertInertia(fn (Assert $page) => $page
            ->where('posDraft.attention', false));

        // Warning only: nothing is cancelled, deleted, paid or confirmed.
        $this->assertSame('draft', SalesOrder::query()->findOrFail($idle)->status->value);
    }

    // --- 13/14/15. legacy rows, feature gate, dry run -------------------------------------------------

    public function test_legacy_drafts_with_null_activity_fall_back_to_updated_at(): void
    {
        $emptyLegacy = $this->emptyDraft(null);
        DB::table('sales_orders')->where('id', $emptyLegacy)->update(['pos_last_activity_at' => null, 'updated_at' => now()->subHours(3)]);
        $fullLegacy = $this->heldDraftWithLine();
        DB::table('sales_orders')->where('id', $fullLegacy)->update(['pos_last_activity_at' => null, 'updated_at' => now()->subHours(40)]);

        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);

        $this->assertNull(SalesOrder::query()->find($emptyLegacy), 'An old EMPTY legacy cart is removed.');
        $this->assertNotNull(SalesOrder::query()->find($fullLegacy), 'A legacy cart with products is only flagged, never deleted.');
    }

    public function test_the_scheduled_cleanup_is_disabled_by_default_and_follows_the_feature_gate(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'pos:cleanup-drafts'));
        $this->assertNotNull($event);

        $this->assertFalse((bool) config('pos.drafts.cleanup_enabled'), 'Default (variable missing) = disabled.');
        $this->assertFalse($event->filtersPass(app()));

        config()->set('pos.drafts.cleanup_enabled', true);
        $this->assertTrue($event->filtersPass(app()));
    }

    public function test_dry_run_writes_nothing_and_reports_empty_and_attention_counts(): void
    {
        $empty = $this->emptyDraft(now()->subHours(3));
        $idle = $this->heldDraftWithLine();
        $this->setActivity($idle, now()->subHours(30));
        $before = [DB::table('sales_orders')->count(), DB::table('sales_order_lines')->count(), DB::table('audit_logs')->count()];

        $this->assertSame(0, Artisan::call('pos:cleanup-drafts'));
        $output = Artisan::output();

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('NEVER deleted', $output);
        $this->assertSame($before, [DB::table('sales_orders')->count(), DB::table('sales_order_lines')->count(), DB::table('audit_logs')->count()]);
        $this->assertNotNull(SalesOrder::query()->find($empty));
    }

    public function test_repeated_scheduled_cleanup_is_idempotent(): void
    {
        $this->emptyDraft(now()->subHours(3));

        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $first = DB::table('audit_logs')->where('event', 'sales_order.pos_draft_discarded')->count();
        $this->assertSame(0, Artisan::call('pos:cleanup-drafts', ['--execute' => true]));

        $this->assertSame(1, $first);
        $this->assertSame($first, DB::table('audit_logs')->where('event', 'sales_order.pos_draft_discarded')->count());
    }

    // --- 6/7/8. manual cancel + safe delete ---------------------------------------------------------------

    public function test_cancelling_a_draft_creates_no_document_payment_or_stock_movement(): void
    {
        $draft = $this->heldDraftWithLine();
        $movements = DB::table('inventory_movements')->count();
        $before = $this->balance($this->organization, $this->warehouse, $this->variant);
        // Drafts reserve nothing (stock is reserved at confirmation), so cancelling cannot touch balances.
        $this->assertSame('0.0000', $before->reserved);

        $this->actingAs($this->owner)->post("/sales/orders/{$draft}/cancel", ['reason' => 'Client parti'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', SalesOrder::query()->findOrFail($draft)->status->value);
        $after = $this->balance($this->organization, $this->warehouse, $this->variant);
        $this->assertSame(['10.0000', '0.0000'], [$after->on_hand, $after->reserved]);
        $this->assertSame($movements, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('inventory_reservations')->count());
    }

    public function test_a_cancelled_clean_draft_can_then_be_deleted_with_an_audit_record(): void
    {
        $draft = $this->heldDraftWithLine();
        $this->actingAs($this->owner)->post("/sales/orders/{$draft}/cancel", ['reason' => 'Annulée'])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get("/sales/orders/{$draft}")->assertInertia(fn (Assert $page) => $page->where('posDraft.can_delete', true));
        $this->actingAs($this->owner)->delete("/sales/orders/{$draft}")->assertRedirect(route('sales.orders.index'));

        $this->assertNull(SalesOrder::query()->find($draft));
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.pos_draft_discarded', 'auditable_id' => $draft]);
        $this->assertSame(0, DB::table('sales_order_lines')->where('sales_order_id', $draft)->count());
    }

    public function test_deleting_requires_cancellation_first_and_rejects_orders_with_history(): void
    {
        $draft = $this->heldDraftWithLine();
        // A still-open draft cannot be hard-deleted through the order endpoint.
        $this->actingAs($this->owner)->delete("/sales/orders/{$draft}")->assertForbidden();
        $this->assertNotNull(SalesOrder::query()->find($draft));

        // A cancelled order that was once confirmed keeps its record (shown under « Annulées »).
        $this->actingAs($this->owner)->post("/sales/orders/{$draft}/cancel", ['reason' => 'x'])->assertSessionHasNoErrors();
        DB::table('sales_orders')->where('id', $draft)->update(['confirmed_at' => now()->subDay()]);
        $this->actingAs($this->owner)->delete("/sales/orders/{$draft}")->assertForbidden();

        $this->assertNotNull(SalesOrder::query()->find($draft));
        $this->actingAs($this->owner)->get('/sales/orders?status=cancelled')->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('summary.annulees', 1));
    }

    public function test_confirmed_orders_can_never_be_deleted(): void
    {
        $draft = $this->heldDraftWithLine();
        DB::table('sales_orders')->where('id', $draft)->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        $this->actingAs($this->owner)->delete("/sales/orders/{$draft}")->assertForbidden();
        $this->assertSame(PosDraftLifecycle::OUTCOME_SKIPPED_GONE, app(PosDraftLifecycle::class)->discard($this->owner, SalesOrder::query()->findOrFail($draft), PosDraftLifecycle::REASON_ABANDONED)['outcome']);
        $this->assertNotNull(SalesOrder::query()->find($draft));
    }

    // --- 10. organization / store / source isolation --------------------------------------------------------

    public function test_other_organizations_and_non_pos_drafts_are_never_touched(): void
    {
        $otherOwner = User::factory()->create();
        $otherOrg = $this->createOrganization($otherOwner, 'Other Org');
        $otherStore = $this->createStore($otherOrg, $otherOwner);
        $otherWarehouse = $this->createWarehouse($otherOrg, 'Other WH');
        $foreign = $this->createPosDraft($otherOwner, $otherOrg, $otherStore, $otherWarehouse);
        $this->setActivity($foreign->id, now()->subHours(5));
        $manual = $this->createDraftOrder($this->owner, $this->organization, $this->store); // B2B / manual draft, no lines
        $this->setActivity($manual->id, now()->subHours(5));

        // A user of org A cannot abandon org B's draft, and the sweep / command never reach it.
        $this->abandon($foreign->id)->assertStatus(404);
        $this->actingPos()->get(route('pos.index'))->assertOk();
        Artisan::call('pos:cleanup-drafts', ['--execute' => true, '--organization' => $this->organization->id]);

        $this->assertNotNull(SalesOrder::query()->find($foreign->id));
        $this->assertNotNull(SalesOrder::query()->find($manual->id), 'B2B / manual drafts are never removed by the POS cleanup.');
        $this->abandon($manual->id)->assertOk()->assertJsonPath('outcome', PosDraftLifecycle::OUTCOME_SKIPPED_GONE);
        $this->assertNotNull(SalesOrder::query()->find($manual->id));
    }

    public function test_a_user_without_update_permission_cannot_abandon_a_cart(): void
    {
        $draft = $this->openCart();
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $viewer, ['pos.access', 'sales_orders.view']);
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->organization, $this->store);

        $this->actingAs($viewer)->postJson(route('pos.drafts.abandon', $draft))->assertForbidden();
        $this->assertNotNull(SalesOrder::query()->find($draft));
    }

    // --- 18. fiscal / history protection ------------------------------------------------------------------------

    public function test_an_empty_cart_carrying_history_is_blocked_not_deleted_by_every_path(): void
    {
        $draft = $this->emptyDraft(now()->subHours(3));
        $order = SalesOrder::query()->findOrFail($draft);
        // A revision row is consequential commercial history even when the cart has no lines.
        DB::table('sales_order_revisions')->insert([
            'organization_id' => $order->organization_id, 'store_id' => $order->store_id, 'sales_order_id' => $order->id,
            'revision_number' => 1, 'status' => 'completed', 'reason' => 'Correction', 'before_snapshot' => '{}',
            'initiated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = app(PosDraftLifecycle::class)->discard($this->owner, $order, PosDraftLifecycle::REASON_ABANDONED);
        $scheduler = app(PosDraftLifecycle::class)->discard(null, $order, PosDraftLifecycle::REASON_EMPTY);
        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);

        $this->assertSame(PosDraftLifecycle::OUTCOME_BLOCKED, $user['outcome']);
        $this->assertContains('revisions', $user['reasons']);
        $this->assertSame(PosDraftLifecycle::OUTCOME_BLOCKED, $scheduler['outcome']);
        $this->assertNotNull(SalesOrder::query()->find($draft));
    }

    // --- helpers ----------------------------------------------------------------------------------------------------

    private function actingPos(): static
    {
        return $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)->actingAs($this->owner);
    }

    private function abandon(int $draft): TestResponse
    {
        return $this->actingPos()->postJson(route('pos.drafts.abandon', $draft));
    }

    private function openCart(): int
    {
        return $this->actingPos()->postJson(route('pos.drafts.store'), ['warehouse_id' => $this->warehouse->id])
            ->assertOk()->json('active_sale.id');
    }

    private function addLine(int $draft): int
    {
        return (int) $this->actingPos()
            ->postJson(route('pos.drafts.lines.store', $draft), $this->posCatalogLine($this->variant, ['quantity' => '1', 'warehouse_id' => $this->warehouse->id]))
            ->assertOk()->json('active_sale.lines.0.id');
    }

    private function removeLine(int $draft, int $lineId): void
    {
        $this->actingPos()->deleteJson(route('pos.drafts.lines.destroy', [$draft, $lineId]))->assertOk();
    }

    private function setActivity(int $draft, $at): void
    {
        DB::table('sales_orders')->where('id', $draft)->update(['pos_last_activity_at' => $at, 'updated_at' => $at]);
    }

    /** A held POS draft with one line on another terminal, so each call yields an independent cart. */
    private function heldDraftWithLine(): int
    {
        $draft = $this->createPosDraft($this->owner, $this->organization, $this->store, $this->warehouse);
        $this->addCatalogLine($this->owner, $draft, $this->variant, $this->warehouse, ['quantity' => '1.0000']);
        DB::table('sales_orders')->where('id', $draft->id)->update(['pos_terminal_id' => (string) Str::uuid(), 'pos_held_at' => now()]);

        return $draft->id;
    }

    private function emptyDraft($activityAt): int
    {
        $draft = $this->createPosDraft($this->owner, $this->organization, $this->store, $this->warehouse);
        DB::table('sales_orders')->where('id', $draft->id)->update(['pos_terminal_id' => (string) Str::uuid()]);
        if ($activityAt !== null) {
            $this->setActivity($draft->id, $activityAt);
        }

        return $draft->id;
    }
}
