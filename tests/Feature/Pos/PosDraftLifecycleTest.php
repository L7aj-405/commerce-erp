<?php

namespace Tests\Feature\Pos;

use App\Enums\InventoryReservationStatus;
use App\Http\Controllers\PosController;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReservationManager;
use App\Services\Pos\PosDraftLifecycle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

/**
 * POS draft lifecycle: no persistent empty drafts on open, reuse across add /
 * remove cycles, activity-based expiry, safe cleanup and manual deletion.
 */
class PosDraftLifecycleTest extends PosTestCase
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
        $this->variant = $this->createProduct($this->organization, 'Lifecycle Product', 'LIFE-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->variant, '10.0000');
        $this->terminal = (string) Str::uuid();
    }

    // --- A. empty cart behaviour ---------------------------------------------------

    public function test_opening_an_empty_pos_creates_no_persistent_draft(): void
    {
        $this->actingAs($this->owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index')->where('activeSale', null));

        $this->assertSame(0, SalesOrder::query()->count());
    }

    public function test_adding_and_removing_a_product_repeatedly_never_accumulates_drafts(): void
    {
        $first = $this->openCart();
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $lineId = $this->addLine($first);
            $this->removeLine($first, $lineId);
            $this->assertSame($first, $this->openCart(), 'The same active draft is reused.');
        }

        $this->assertSame(1, SalesOrder::query()->where('source', 'pos')->count());
    }

    public function test_removing_the_last_product_leaves_an_empty_hidden_reusable_cart_not_a_visible_order(): void
    {
        $draft = $this->openCart();
        $lineId = $this->addLine($draft);
        $this->removeLine($draft, $lineId)->assertJsonPath('active_sale.id', $draft)->assertJsonPath('active_sale.lines', []);

        // Hidden from the Sales Orders list and its counters while empty.
        $this->actingAs($this->owner)->get('/sales/orders')->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 0)->has('orders.data', 0));

        // Once it has a line it becomes a normal visible order again.
        $this->addLine($draft);
        $this->actingAs($this->owner)->get('/sales/orders')->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)->has('orders.data', 1));
    }

    public function test_rapid_duplicate_draft_creation_is_collapsed_into_one_draft(): void
    {
        $ids = [];
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $ids[] = $this->openCart();
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, SalesOrder::query()->where('source', 'pos')->count());
    }

    public function test_a_stale_draft_id_is_not_found_instead_of_creating_hidden_state(): void
    {
        $draft = $this->openCart();
        $this->discardNow($draft);

        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)->actingAs($this->owner)
            ->postJson(route('pos.drafts.lines.store', $draft), $this->posCatalogLine($this->variant, ['quantity' => '1', 'warehouse_id' => $this->warehouse->id]))->assertNotFound();
        $this->assertSame(0, SalesOrder::query()->count());
    }

    // --- B. activity / resume --------------------------------------------------------

    public function test_meaningful_changes_stamp_activity_but_page_loads_do_not(): void
    {
        $draft = $this->openCart();
        $this->assertNotNull(SalesOrder::query()->findOrFail($draft)->pos_last_activity_at);
        $this->addLine($draft); // a cart WITH products: an idle EMPTY cart is (rightly) swept on reload

        $this->setActivity($draft, now()->subHours(5));
        $this->actingAs($this->owner)->get(route('pos.index'))->assertOk();
        $this->assertTrue(SalesOrder::query()->findOrFail($draft)->pos_last_activity_at->lt(now()->subHours(4)), 'A refresh must not extend the expiry.');

        $this->addLine($draft);
        $this->assertTrue(SalesOrder::query()->findOrFail($draft)->pos_last_activity_at->gt(now()->subMinute()));
    }

    public function test_a_non_empty_draft_can_be_resumed_without_creating_another(): void
    {
        $draft = $this->openCart();
        $this->addLine($draft);
        $order = SalesOrder::query()->findOrFail($draft);
        $order->pos_held_at = now();
        $order->save();

        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)->actingAs($this->owner)
            ->postJson(route('pos.drafts.resume', $draft))->assertOk()->assertJsonPath('active_sale.id', $draft);

        $this->assertSame(1, SalesOrder::query()->where('source', 'pos')->count());
    }

    // --- C. expiry cleanup -----------------------------------------------------------

    public function test_scheduled_cleanup_removes_only_idle_empty_carts_and_never_carts_with_products(): void
    {
        $idleWithProducts = $this->draftWithLine('idle', now()->subHours(25));
        $recent = $this->draftWithLine('recent', now()->subHours(23));
        $emptyOld = $this->emptyDraft(now()->subHours(3));
        $emptyFresh = $this->emptyDraft(now()->subMinutes(10));

        $this->assertSame(0, Artisan::call('pos:cleanup-drafts', ['--execute' => true]));

        $this->assertNull(SalesOrder::query()->find($emptyOld));
        $this->assertNotNull(SalesOrder::query()->find($idleWithProducts), 'A cart with products is NEVER auto-deleted: it is only flagged.');
        $this->assertNotNull(SalesOrder::query()->find($recent));
        $this->assertNotNull(SalesOrder::query()->find($emptyFresh));
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.pos_draft_discarded', 'auditable_id' => $emptyOld]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'sales_order.pos_draft_discarded', 'auditable_id' => $idleWithProducts]);
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        $idle = $this->draftWithLine('idle', now()->subHours(30));
        $emptyOld = $this->emptyDraft(now()->subHours(3));

        Artisan::call('pos:cleanup-drafts');
        $output = Artisan::output();

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertNotNull(SalesOrder::query()->find($idle));
        $this->assertNotNull(SalesOrder::query()->find($emptyOld));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'sales_order.pos_draft_discarded']);
    }

    public function test_repeated_cleanup_is_idempotent(): void
    {
        $this->emptyDraft(now()->subHours(48));

        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $firstAuditCount = DB::table('audit_logs')->where('event', 'sales_order.pos_draft_discarded')->count();
        $this->assertSame(0, Artisan::call('pos:cleanup-drafts', ['--execute' => true]));

        $this->assertSame(1, $firstAuditCount);
        $this->assertSame($firstAuditCount, DB::table('audit_logs')->where('event', 'sales_order.pos_draft_discarded')->count());
    }

    public function test_cleanup_honours_the_batch_size(): void
    {
        foreach (range(1, 3) as $n) {
            $this->emptyDraft(now()->subHours(3), (string) Str::uuid());
        }

        Artisan::call('pos:cleanup-drafts', ['--execute' => true, '--batch' => 2]);

        $this->assertSame(1, SalesOrder::query()->where('source', 'pos')->count());
    }

    public function test_legacy_empty_drafts_without_activity_use_updated_at_and_legacy_carts_with_products_are_kept(): void
    {
        $legacy = $this->emptyDraft(null);
        DB::table('sales_orders')->where('id', $legacy)->update(['pos_last_activity_at' => null, 'updated_at' => now()->subMinutes(20)]);

        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $this->assertNotNull(SalesOrder::query()->find($legacy), 'A recent updated_at keeps a legacy empty draft.');

        DB::table('sales_orders')->where('id', $legacy)->update(['updated_at' => now()->subHours(3)]);
        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $this->assertNull(SalesOrder::query()->find($legacy));

        $withProducts = $this->draftWithLine('legacy-products', null);
        DB::table('sales_orders')->where('id', $withProducts)->update(['pos_last_activity_at' => null, 'updated_at' => now()->subDays(60)]);
        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $this->assertNotNull(SalesOrder::query()->find($withProducts), 'Legacy carts with products are never deleted.');
    }

    // --- E. other drafts / protected orders -------------------------------------------

    public function test_non_pos_drafts_are_preserved_indefinitely(): void
    {
        $manual = $this->createDraftOrder($this->owner, $this->organization, $this->store);
        $this->addCatalogLine($this->owner, $manual, $this->variant, $this->warehouse);
        DB::table('sales_orders')->where('id', $manual->id)->update(['updated_at' => now()->subYear(), 'created_at' => now()->subYear()]);

        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);

        $this->assertNotNull(SalesOrder::query()->find($manual->id));
        $this->assertSame('manual', SalesOrder::query()->find($manual->id)->source->value);
    }

    public function test_confirmed_and_converted_orders_are_never_deleted(): void
    {
        $confirmed = $this->draftWithLine('confirmed', now()->subDays(3));
        DB::table('sales_orders')->where('id', $confirmed)->update(['status' => 'confirmed']);
        $converted = $this->draftWithLine('converted', now()->subDays(3));
        DB::table('sales_orders')->where('id', $converted)->update(['pos_converted_at' => now()->subDay()]);

        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);

        $this->assertNotNull(SalesOrder::query()->find($confirmed));
        $this->assertNotNull(SalesOrder::query()->find($converted));
        $result = app(PosDraftLifecycle::class)->discard(null, SalesOrder::query()->findOrFail($confirmed), PosDraftLifecycle::REASON_EMPTY);
        $this->assertSame(PosDraftLifecycle::OUTCOME_SKIPPED_GONE, $result['outcome']);
    }

    public function test_cleanup_state_change_under_lock_wins_over_cleanup(): void
    {
        // Confirmation that lands before cleanup takes the lock makes cleanup skip.
        $draft = $this->emptyDraft(now()->subHours(48));
        $stale = SalesOrder::query()->findOrFail($draft); // the cleaner read it as an idle empty cart...
        DB::table('sales_orders')->where('id', $draft)->update(['status' => 'confirmed']); // ...then it was confirmed
        $this->assertSame(PosDraftLifecycle::OUTCOME_SKIPPED_GONE, app(PosDraftLifecycle::class)->discard(null, $stale, PosDraftLifecycle::REASON_EMPTY)['outcome']);

        // An edit that lands first refreshes the activity stamp: cleanup skips.
        $edited = $this->emptyDraft(now()->subHours(48));
        $staleEdited = SalesOrder::query()->findOrFail($edited);
        DB::table('sales_orders')->where('id', $edited)->update(['pos_last_activity_at' => now()]);
        $this->assertSame(PosDraftLifecycle::OUTCOME_SKIPPED_ACTIVE, app(PosDraftLifecycle::class)->discard(null, $staleEdited, PosDraftLifecycle::REASON_EMPTY)['outcome']);
        $this->assertNotNull(SalesOrder::query()->find($edited));

        // A product added meanwhile turns it into a cart the scheduler must never remove.
        $filled = $this->emptyDraft(now()->subHours(48));
        $staleFilled = SalesOrder::query()->findOrFail($filled);
        $this->addCatalogLine($this->owner, SalesOrder::query()->findOrFail($filled), $this->variant, $this->warehouse, ['quantity' => '1.0000']);
        $this->assertSame(PosDraftLifecycle::OUTCOME_SKIPPED_ACTIVE, app(PosDraftLifecycle::class)->discard(null, $staleFilled, PosDraftLifecycle::REASON_EMPTY)['outcome']);
        $this->assertNotNull(SalesOrder::query()->find($filled));
    }

    // --- D. manual deletion -----------------------------------------------------------

    public function test_manual_deletion_requires_the_cancel_permission(): void
    {
        $draft = $this->openCart();
        $cashier = User::factory()->create();
        $this->addOrganizationMember($this->organization, $cashier, ['pos.access', 'sales_orders.create', 'sales_orders.update', 'sales_orders.view']);
        $this->addStoreMember($this->store, $cashier);
        $this->activate($cashier, $this->organization, $this->store);

        $this->actingAs($cashier)->deleteJson(route('pos.drafts.destroy', $draft))->assertForbidden();
        $this->assertNotNull(SalesOrder::query()->find($draft));
    }

    public function test_an_empty_draft_is_removed_immediately_without_confirmation(): void
    {
        $draft = $this->openCart();

        $this->actingAs($this->owner)->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)
            ->deleteJson(route('pos.drafts.destroy', $draft))->assertOk()->assertJsonPath('active_sale', null);

        $this->assertNull(SalesOrder::query()->find($draft));
    }

    // --- G. inventory consistency ------------------------------------------------------

    public function test_discard_never_changes_on_hand_and_only_releases_reservations_it_owns(): void
    {
        $draft = $this->draftWithLine('owner', now()->subHours(1));
        $other = $this->draftWithLine('other', now()->subHours(1));
        $mine = $this->attachReservation($draft, '2.0000', 'A');
        $theirs = $this->attachReservation($other, '3.0000', 'B');
        $before = $this->balance($this->organization, $this->warehouse, $this->variant);
        $this->assertSame('5.0000', $before->reserved);

        // The unattended scheduler never auto-releases reservations: it reports the draft as blocked.
        $this->setActivity($draft, now()->subDays(3));
        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $this->assertNotNull(SalesOrder::query()->find($draft));
        $this->assertSame(InventoryReservationStatus::Active, InventoryReservation::query()->findOrFail($mine)->status);

        // An authorized manual discard releases ONLY this draft's reservation, exactly once.
        $this->actingAs($this->owner)->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)
            ->deleteJson(route('pos.drafts.destroy', $draft), ['confirm' => true])->assertOk();

        $this->assertNull(SalesOrder::query()->find($draft));
        $this->assertSame(InventoryReservationStatus::Released, InventoryReservation::query()->findOrFail($mine)->status);
        $this->assertSame(InventoryReservationStatus::Active, InventoryReservation::query()->findOrFail($theirs)->status);
        $after = $this->balance($this->organization, $this->warehouse, $this->variant);
        $this->assertSame('10.0000', $after->on_hand);
        $this->assertSame('3.0000', $after->reserved);
    }

    public function test_plain_drafts_hold_no_reservation_and_discard_leaves_stock_untouched(): void
    {
        $draft = $this->draftWithLine('plain', now()->subHours(48));

        // The scheduler leaves a cart with products alone; an authorized manual discard removes it.
        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $this->assertNotNull(SalesOrder::query()->find($draft));
        $this->actingAs($this->owner)->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)
            ->deleteJson(route('pos.drafts.destroy', $draft), ['confirm' => true])->assertOk();

        $balance = $this->balance($this->organization, $this->warehouse, $this->variant);
        $this->assertNull(SalesOrder::query()->find($draft));
        $this->assertSame('10.0000', $balance->on_hand);
        $this->assertSame('0.0000', $balance->reserved);
        $this->assertSame(0, DB::table('inventory_reservations')->count());
    }

    // --- tenancy ----------------------------------------------------------------------

    public function test_cleanup_can_be_scoped_to_one_organization(): void
    {
        $mine = $this->emptyDraft(now()->subHours(5));
        $otherOwner = User::factory()->create();
        $otherOrg = $this->createOrganization($otherOwner, 'Other Org');
        $otherStore = $this->createStore($otherOrg, $otherOwner);
        $otherWarehouse = $this->createWarehouse($otherOrg, 'Other WH');
        $otherDraft = $this->createPosDraft($otherOwner, $otherOrg, $otherStore, $otherWarehouse);
        DB::table('sales_orders')->where('id', $otherDraft->id)->update(['pos_last_activity_at' => now()->subHours(5), 'updated_at' => now()->subHours(5)]);

        Artisan::call('pos:cleanup-drafts', ['--execute' => true, '--organization' => $this->organization->id]);

        $this->assertNull(SalesOrder::query()->find($mine));
        $this->assertNotNull(SalesOrder::query()->find($otherDraft->id), 'Another organization is never touched.');
    }

    // --- helpers ------------------------------------------------------------------------

    private function openCart(): int
    {
        return $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)->actingAs($this->owner)
            ->postJson(route('pos.drafts.store'), ['warehouse_id' => $this->warehouse->id])
            ->assertOk()->json('active_sale.id');
    }

    private function addLine(int $draft): int
    {
        $response = $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)->actingAs($this->owner)
            ->postJson(route('pos.drafts.lines.store', $draft), $this->posCatalogLine($this->variant, ['quantity' => '1', 'warehouse_id' => $this->warehouse->id]))->assertOk();

        return (int) $response->json('active_sale.lines.0.id');
    }

    private function removeLine(int $draft, int $lineId): TestResponse
    {
        return $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $this->terminal)->actingAs($this->owner)
            ->deleteJson(route('pos.drafts.lines.destroy', [$draft, $lineId]))->assertOk();
    }

    private function setActivity(int $draft, $at): void
    {
        DB::table('sales_orders')->where('id', $draft)->update(['pos_last_activity_at' => $at, 'updated_at' => $at]);
    }

    private function discardNow(int $draft): void
    {
        app(PosDraftLifecycle::class)->discard($this->owner, SalesOrder::query()->findOrFail($draft), PosDraftLifecycle::REASON_MANUAL, true);
        $this->assertNull(SalesOrder::query()->find($draft));
    }

    /** A held POS draft with one line (held so each helper call yields an independent cart). */
    private function draftWithLine(string $label, $activityAt): int
    {
        $draft = $this->createPosDraft($this->owner, $this->organization, $this->store, $this->warehouse);
        $this->addCatalogLine($this->owner, $draft, $this->variant, $this->warehouse, ['quantity' => '1.0000']);
        DB::table('sales_orders')->where('id', $draft->id)->update(['pos_terminal_id' => (string) Str::uuid(), 'pos_held_at' => now()]);
        if ($activityAt !== null) {
            $this->setActivity($draft->id, $activityAt);
        }

        return $draft->id;
    }

    private function emptyDraft($activityAt, ?string $terminal = null): int
    {
        $draft = $this->createPosDraft($this->owner, $this->organization, $this->store, $this->warehouse);
        DB::table('sales_orders')->where('id', $draft->id)->update(['pos_terminal_id' => $terminal ?? (string) Str::uuid()]);
        $this->setActivity($draft->id, $activityAt);

        return $draft->id;
    }

    /** Test-only edge case: links an Active reservation to the draft's first line through an allocation. */
    private function attachReservation(int $draft, string $quantity, string $reference): int
    {
        $reservation = app(InventoryReservationManager::class)->reserve($this->owner, $this->organization, $this->warehouse, $this->variant, $quantity, 'test', $draft, $reference);
        $line = SalesOrder::query()->findOrFail($draft)->lines()->firstOrFail();
        // Draft lines already own an allocation row (without reservation): link it.
        $linked = DB::table('sales_order_inventory_allocations')->where('sales_order_line_id', $line->id)->where('warehouse_id', $this->warehouse->id)
            ->update(['inventory_reservation_id' => $reservation->id]);
        $this->assertSame(1, $linked);

        return (int) $reservation->id;
    }
}
