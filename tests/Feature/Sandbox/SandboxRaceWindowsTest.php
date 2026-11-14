<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Procurement\CreateProcurementAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Http\Controllers\PosController;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\PurchaseOrderLine;
use App\Models\Sandbox;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Sandbox\SandboxResetExecutor;
use App\Services\Sandbox\SandboxResetPlanner;
use App\Services\Sandbox\SandboxStaleGenerationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\QuotationTestCase;

/**
 * Phase 10.1 — the last single-statement Test writes run inside a short
 * transaction (so SandboxGenerationFence's shared lock covers them until
 * commit), a reset landing right before them yields the controlled stale
 * answer with nothing surviving, and the bell never shows Production
 * notifications in Test Mode.
 */
class SandboxRaceWindowsTest extends QuotationTestCase
{
    private int $baseline;

    /** @var list<int> transaction levels at which the watched statements ran */
    private array $levels = [];

    public function test_pos_draft_header_write_is_transactional_and_cannot_survive_a_reset(): void
    {
        [$owner, $org, , , $warehouse] = $this->fixture();
        $this->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $this->enterTest();
        // The order is created (its own transaction), then the POS header is saved: the reset lands in between.
        $this->recordLevel(SalesOrder::class, 'saving', fn (SalesOrder $o) => $o->exists && $o->isDirty('pos_terminal_id'));
        $this->resetAfterCommitOf(SalesOrder::class, $org, $owner);
        $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertStatus(409)->assertJson(['code' => 'SANDBOX_STALE_GENERATION']);
        $this->assertTransactional();
        $this->assertNothingSurvived($org);
        $this->assertSame(0, DB::table('sales_orders')->count());
    }

    public function test_reservation_release_cannot_survive_a_reset(): void
    {
        [$owner, $org, , $variant, $warehouse] = $this->fixture();
        $this->enterTest();
        $this->post('/inventory/reservations', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => '2'])->assertRedirect();
        $reservation = InventoryReservation::query()->sole();
        $this->recordLevel(InventoryReservation::class, 'saving', fn (InventoryReservation $r) => $r->isDirty('status'));
        $done = false;
        $this->app->afterResolving(ReleaseReservationAction::class, function () use (&$done, $org, $owner) {
            if (! $done) {
                $done = true;
                $this->resetTestData($org, $owner);
            }
        });

        $this->postJson("/inventory/reservations/{$reservation->id}/release")->assertStatus(409)->assertJson(['code' => 'SANDBOX_STALE_GENERATION']);
        $this->assertSame([], $this->levels, 'The stale release must be refused before writing.');
        $this->assertNothingSurvived($org);
        // Same write on a current generation: it runs inside a request transaction.
        $this->post('/test-mode/enter')->assertRedirect();
        $this->post('/inventory/reservations', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => '1'])->assertRedirect();
        $this->postJson('/inventory/reservations/'.InventoryReservation::query()->sole()->id.'/release')->assertSessionHasNoErrors();
        $this->assertSame('released', InventoryReservation::query()->sole()->status->value);
        $this->assertTransactional();
    }

    public function test_purchase_order_line_update_cannot_survive_a_reset(): void
    {
        [$owner, $org, $store, $variant, $warehouse] = $this->fixture();
        $supplier = new Supplier;
        $supplier->organization_id = $org->id;
        $supplier->name = 'Fournisseur';
        $supplier->active = true;
        $supplier->save();
        $this->enterTest();
        $order = $this->createDraftOrder($owner, $org, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        app(CreateProcurementAction::class)->execute($owner, $order, $line, $supplier, ['quantity' => '2']);
        app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->forSalesOrder($owner, $order->fresh(), $supplier);
        $poLine = PurchaseOrderLine::query()->sole();
        $this->assertSame('sandbox_purchase_order_lines', $poLine->getTable());
        $this->get("/sales/orders/{$order->id}")->assertOk(); // fresh Test request context (generation 1)

        $this->recordLevel(PurchaseOrderLine::class, 'saving', fn (PurchaseOrderLine $l) => $l->isDirty('unit_price'));
        $poLine->forceFill(['unit_price' => '55.0000'])->save(); // current generation: allowed, transactional
        $this->assertTransactional();
        $this->resetTestData($org, $owner);                        // another user's reset commits
        try {
            $poLine->forceFill(['unit_price' => '77.0000'])->save();
            $this->fail('A stale purchase-order line update was accepted.');
        } catch (SandboxStaleGenerationException $e) {
            $this->assertSame([1, 2], [$e->requestGeneration, $e->currentGeneration]);
        }
        $this->assertNothingSurvived($org, writeAudited: false);
        $this->assertSame(0, DB::table('sandbox_purchase_order_lines')->count());
    }

    public function test_provisional_allocation_insert_cannot_survive_a_reset(): void
    {
        [$owner, $org, , $variant, $warehouse] = $this->fixture();
        $this->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $this->enterTest();
        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $line = ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '1', 'discount_type' => 'none', 'discount_value' => '0'];
        // Current generation: the provisional allocation is written inside the line's transaction.
        $this->recordLevel(SalesOrderInventoryAllocation::class, 'creating', fn () => true);
        $this->postJson("/pos/drafts/{$cart}/lines", $line)->assertOk();
        $this->assertTransactional();
        $this->assertSame(1, DB::table('sandbox_sales_order_inventory_allocations')->count());

        // A reset committing before that transaction: the line + allocation write is refused as a whole.
        $done = false;
        $this->app->afterResolving(SaveSalesOrderLineAction::class, function () use (&$done, $org, $owner) {
            if (! $done) {
                $done = true;
                $this->resetTestData($org, $owner);
            }
        });
        $this->postJson("/pos/drafts/{$cart}/lines", $line)->assertStatus(409)->assertJson(['code' => 'SANDBOX_STALE_GENERATION']);
        $this->assertNothingSurvived($org);
        $this->assertSame(0, DB::table('sandbox_sales_order_inventory_allocations')->count());
    }

    public function test_test_mode_bell_never_exposes_production_notifications_and_production_bell_is_unchanged(): void
    {
        [$owner, $org] = $this->fixture();
        $notification = $this->productionNotification($owner, $org);
        $production = $this->getJson('/notifications/feed')->assertOk()->json();
        $this->assertSame(1, $production['unread_count']);
        $this->assertSame($notification->id, $production['recent'][0]['id']);
        $this->assertArrayNotHasKey('environment', $production);

        $this->enterTest();
        $feed = $this->getJson('/notifications/feed')->assertOk()->json();
        $this->assertSame(['unread_count' => 0, 'recent' => [], 'environment' => 'test'], array_intersect_key($feed, array_flip(['unread_count', 'recent', 'environment'])));
        $shared = $this->get('/sales/orders')->assertOk()->viewData('page')['props']['notifications'];
        $this->assertSame([0, [], 'test'], [$shared['unread_count'], $shared['recent'], $shared['environment']]);
        $this->assertStringNotContainsString('Alerte Production', $this->get('/sales/orders')->getContent());
        // The Production inbox cannot be read or changed from Test Mode.
        $this->getJson('/notifications')->assertForbidden();
        $this->patchJson("/notifications/{$notification->id}/read")->assertForbidden();
        $this->postJson('/notifications/read-all')->assertForbidden();

        // A stale (reset) Test session stays on the empty Test bell, not the Production inbox.
        $this->resetTestData($org, $owner);
        $this->assertSame([], $this->getJson('/notifications/feed')->assertOk()->json('recent'));

        $this->post('/test-mode/leave')->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
        $this->assertEquals($production, $this->getJson('/notifications/feed')->assertOk()->json());
    }

    /** @return array{User, Organization, \App\Models\Store, \App\Models\ProductVariant, \App\Models\Warehouse} */
    private function fixture(): array
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $variant, '5.0000');
        $this->actingAs($owner);
        $this->withCredentials();
        $this->baseline = DB::connection()->transactionLevel();

        return [$owner, $org, $store, $variant, $warehouse];
    }

    /** The guarded write (and its fence check) ran inside a transaction opened by the request (the fence lock is held to commit). */
    private function assertTransactional(): void
    {
        $this->assertNotEmpty($this->levels, 'The guarded Test write never ran.');
        foreach ($this->levels as $level) {
            $this->assertGreaterThan($this->baseline, $level);
        }
    }

    /** Transaction level at which a guarded model write starts (inside save(), before its SQL and fence check). */
    private function recordLevel(string $model, string $event, callable $condition): void
    {
        $model::$event(function ($row) use ($condition) {
            if ($condition($row)) {
                $this->levels[] = DB::connection()->transactionLevel();
            }
        });
    }

    /**
     * Another user's reset commits right after the request's transaction that
     * created `$model` committed, i.e. in the window before the next guarded write. (On the single
     * SQLite test connection a "concurrent" reset can only commit between the
     * request's transactions; on MySQL it runs on its own connection.)
     */
    private function resetAfterCommitOf(string $model, Organization $org, User $owner): void
    {
        $done = false;
        $armed = false;
        $model::created(function () use (&$armed) {
            $armed = true;
        });
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$done, &$armed, $org, $owner) {
            if ($armed && ! $done && $event->connection->transactionLevel() === $this->baseline) {
                $done = true;
                $this->resetTestData($org, $owner);
            }
        });
    }

    private function resetTestData(Organization $org, User $owner): void
    {
        $sandbox = Sandbox::query()->where('organization_id', $org->id)->sole();
        app(SandboxResetExecutor::class)->execute($org, $sandbox, $owner, app(SandboxResetPlanner::class)->preview($sandbox)['fingerprint']);
    }

    private function assertNothingSurvived(Organization $org, bool $writeAudited = true): void
    {
        $sandbox = Sandbox::query()->where('organization_id', $org->id)->sole();
        $this->assertSame(2, $sandbox->generation);
        $this->assertSame(0, array_sum(app(SandboxResetPlanner::class)->counts($sandbox)));
        $this->assertSame($writeAudited ? 1 : 0, DB::table('audit_logs')->where('event', 'sandbox.stale_rejected')->where('new_values', 'like', '%"stage":"write"%')->count());
    }

    private function productionNotification(User $owner, Organization $org): UserNotification
    {
        $notification = new UserNotification;
        foreach (['user_id' => $owner->id, 'organization_id' => $org->id, 'category' => 'inventory', 'severity' => 'warning',
            'title' => 'Alerte Production', 'message' => 'Stock bas PROD-001', 'source_type' => 'test', 'source_id' => '1',
            'event_type' => 'inventory.low', 'dedupe_key' => 'prod-1'] as $key => $value) {
            $notification->{$key} = $value;
        }
        $notification->save();

        return $notification;
    }

    private function enterTest(): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }
}
