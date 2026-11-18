<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Sales\CreateSalesOrderAction;
use App\Http\Controllers\PosController;
use App\Models\Organization;
use App\Models\Sandbox;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use App\Services\OrganizationDataReset\Selective\SelectiveResetCategoryRegistry;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlanner;
use App\Services\OrganizationDataReset\Selective\SelectiveResetSelection;
use App\Services\Sandbox\SandboxProductionResetGuard;
use App\Services\Sandbox\SandboxResetExecutor;
use App\Services\Sandbox\SandboxResetPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QuotationTestCase;

/** Phase 10 — minimal safety checks for the final Test Mode lifecycle hardening. */
class SandboxHardeningTest extends QuotationTestCase
{
    public function test_request_started_on_generation_n_cannot_write_after_a_reset_to_n_plus_one(): void
    {
        [$owner, $org] = $this->fixture();
        $this->enterTest();
        $sandbox = Sandbox::query()->sole();
        $this->resetDuringNextAction($org, $sandbox, $owner);

        // The request resolved generation 1; the reset commits generation 2 before the action writes.
        $this->post('/sales/orders', $this->orderPayload())->assertStatus(303)->assertRedirect('/test-mode')
            ->assertSessionHas('sandbox_stale');
        $this->assertSame(2, $sandbox->fresh()->generation);
        $this->assertSame(0, DB::table('sales_orders')->count());
        $this->assertSame(0, array_sum(app(SandboxResetPlanner::class)->counts($sandbox->fresh())));
        $audit = DB::table('audit_logs')->where('event', 'sandbox.stale_rejected')->sole();
        $values = json_decode($audit->new_values, true);
        $this->assertSame(['write', 1, 2, $sandbox->id], [$values['stage'], $values['session_generation'], $values['current_generation'], $values['sandbox_id']]);
        $this->assertSame($owner->id, (int) $audit->actor_id);

        // JSON clients get a controlled 409, never a 500.
        $this->resetDuringNextAction($org, $sandbox->fresh(), $owner, reEnter: true);
        $this->postJson('/sales/orders', $this->orderPayload())->assertStatus(409)->assertJson(['code' => 'SANDBOX_STALE_GENERATION']);
        $this->assertSame(0, DB::table('sales_orders')->count());
    }

    public function test_production_requests_are_not_affected_by_a_concurrent_sandbox_reset(): void
    {
        [$owner, $org] = $this->fixture();
        $this->enterTest();
        $sandbox = Sandbox::query()->sole();
        $this->leaveTest();
        $this->resetDuringNextAction($org, $sandbox, $owner);

        $this->post('/sales/orders', $this->orderPayload())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $sandbox->fresh()->generation);
        $this->assertSame(1, SalesOrder::query()->whereNull('sandbox_id')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'sandbox.stale_rejected')->count());
    }

    public function test_stale_session_is_redirected_with_an_explanation_and_must_re_enter(): void
    {
        [$owner, $org, $store] = $this->fixture();
        $this->enterTest();
        $this->get('/sales/orders')->assertOk();
        $sandbox = Sandbox::query()->sole();
        // Another user resets the sandbox (Production context, dedicated flow).
        $this->resetAsAnotherUser($org, $sandbox, $owner);

        $this->get('/sales/orders')->assertStatus(303)->assertRedirect('/test-mode');
        $this->getJson('/sales/orders')->assertStatus(409)->assertJson(['code' => 'SANDBOX_STALE_GENERATION',
            'message' => 'Le Mode Test a été réinitialisé. Veuillez entrer à nouveau en Mode Test.']);
        $this->post("/sales/orders", $this->orderPayload())->assertStatus(303)->assertRedirect('/test-mode');
        $this->assertSame(0, DB::table('sales_orders')->count());
        $home = $this->get('/test-mode')->assertOk()->viewData('page')['props'];
        $this->assertTrue($home['stale']);
        $this->assertTrue($home['sandbox']['restricted']);
        $this->assertFalse($home['sandbox']['active']);
        // Page navigation is audited once per stale session, not on every request.
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'sandbox.stale_rejected')->count());

        // Explicit re-entry binds the session to the new generation; Test stock is re-snapshotted.
        $this->post('/test-mode/enter')->assertRedirect('/test-mode');
        $home = $this->get('/test-mode')->assertOk()->viewData('page')['props'];
        $this->assertSame(2, $home['current']['generation']);
        $this->assertNotNull($home['current']['stockInitializedAt']);
        $this->assertFalse($home['stale']);
        $this->get('/sales/orders')->assertOk();
        $entered = json_decode(DB::table('audit_logs')->where('event', 'sandbox.entered')->orderByDesc('id')->value('new_values'), true);
        $this->assertSame(['sandbox_id' => $sandbox->id, 'generation' => 2], $entered);
        $this->leaveTest();
        $left = json_decode(DB::table('audit_logs')->where('event', 'sandbox.left')->orderByDesc('id')->value('old_values'), true);
        $this->assertSame(['sandbox_id' => $sandbox->id, 'generation' => 2], $left);
    }

    public function test_production_reset_is_blocked_while_test_data_exists_and_proceeds_after_the_test_reset(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $this->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $this->createDraftOrder($owner, $org, $store);                 // Production draft
        $this->enterTest();
        $this->posSale($variant, $warehouse, $cash);                    // Test order, payment, stock, fulfillment
        $this->leaveTest();
        $sandbox = Sandbox::query()->sole();
        $testRows = array_sum(app(SandboxResetPlanner::class)->counts($sandbox));

        $plan = app(OrganizationDataResetPreviewService::class)->preview($org, OrganizationDataResetScope::SalesAndDocuments);
        $this->assertFalse($plan->isExecutable());
        $blocker = collect($plan->blockers)->firstWhere('code', 'sandbox.test_data_present');
        $this->assertSame($testRows, $blocker['count']);
        $this->assertSame(SandboxProductionResetGuard::MESSAGE, $blocker['message']);
        $selective = app(SelectiveResetPlanner::class)->plan($org, SelectiveResetSelection::fromArray(['categories' => ['sales_orders']], app(SelectiveResetCategoryRegistry::class)));
        $this->assertFalse($selective->isExecutable());
        $this->assertSame('sandbox_test_data', $selective->blockers[0]['code']);
        // Nothing of either environment was touched by the blocked previews.
        $this->assertSame(2, DB::table('sales_orders')->count());

        $this->resetAsAnotherUser($org, $sandbox, $owner);
        $plan = app(OrganizationDataResetPreviewService::class)->preview($org, OrganizationDataResetScope::SalesAndDocuments);
        $this->assertNotContains('sandbox.test_data_present', array_column($plan->blockers, 'code'));
        $this->assertTrue($plan->isExecutable());
        app(OrganizationDataResetExecutor::class)->execute($org, $owner, $plan);
        $this->assertSame(0, DB::table('sales_orders')->count());
        $this->assertTrue(Sandbox::query()->whereKey($sandbox->id)->exists());
    }

    public function test_production_receivables_no_longer_list_test_orders(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $this->enterTest();
        $order = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $this->post("/sales/orders/{$order->id}/confirm")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed', SalesOrder::query()->findOrFail($order->id)->status->value); // unpaid Test receivable
        $this->leaveTest();

        $props = $this->get('/finance/situation-fin-de-mois')->assertOk()->viewData('page')['props'];
        $this->assertNotContains($order->id, collect($props['receivableOrders'])->pluck('id')->all());
    }

    public function test_supported_test_routes_work_and_blocked_routes_stay_blocked(): void
    {
        [$owner, $org] = $this->fixture();
        $this->enterTest();
        foreach (['/test-mode', '/test-mode/reset', '/sales/orders', '/quotations', '/pos', '/inventory/stock', '/payments', '/procurement', '/sales/returns', '/invoices'] as $uri) {
            $this->get($uri)->assertOk();
        }
        // Production administration, integrations, reporting and master-data writes stay closed.
        foreach (['/settings/data-reset', '/finance/situation-fin-de-mois', '/sales/customers/create', '/work/attention', '/integrations/woocommerce', '/integrations/woocommerce/stock-tasks', '/platform'] as $uri) {
            $this->get($uri)->assertRedirect('/test-mode');
        }
        $this->postJson('/settings/data-reset/preview', ['scope' => 'sales_and_documents'])->assertForbidden()->assertJson(['code' => 'SANDBOX_DOMAIN_UNAVAILABLE']);
        $this->postJson('/sales/customers', ['display_name' => 'X'])->assertForbidden()->assertJson(['code' => 'SANDBOX_DOMAIN_UNAVAILABLE']);
    }

    /** @return array{User, Organization, \App\Models\Store, \App\Models\ProductVariant, \App\Models\Warehouse, \App\Models\FinancialAccount} */
    private function fixture(): array
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $variant, '5.0000');
        $cash = $this->createFinancialAccount($org, ['name' => 'Caisse', 'code' => 'CASH', 'type' => 'cash']);
        $this->actingAs($owner);
        $this->withCredentials();

        return [$owner, $org, $store, $variant, $warehouse, $cash];
    }

    /** Simulates another user's reset committing after this request captured its generation. */
    private function resetDuringNextAction(Organization $org, Sandbox $sandbox, User $owner, bool $reEnter = false): void
    {
        if ($reEnter) {
            $this->post('/test-mode/enter')->assertRedirect();
        }
        $done = false;
        $this->app->afterResolving(CreateSalesOrderAction::class, function () use (&$done, $org, $sandbox, $owner) {
            if (! $done) {
                $done = true;
                $this->resetAsAnotherUser($org, $sandbox->fresh(), $owner);
            }
        });
    }

    private function resetAsAnotherUser(Organization $org, Sandbox $sandbox, User $actor): void
    {
        app(SandboxResetExecutor::class)->execute($org, $sandbox, $actor, app(SandboxResetPlanner::class)->preview($sandbox)['fingerprint']);
    }

    private function orderPayload(): array
    {
        return ['customer_id' => null, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => 'Phase 10'];
    }

    private function posSale($variant, $warehouse, $cash): void
    {
        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->postJson("/pos/drafts/{$cart}/lines", ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '1', 'discount_type' => 'none', 'discount_value' => '0'])->assertOk();
        $total = (string) SalesOrder::query()->findOrFail($cart)->total_incl_tax;
        $this->post('/pos/sales', ['client_operation_id' => (string) Str::uuid(), 'order_id' => $cart,
            'payments' => [['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => $total, 'cash_received' => $total, 'reference' => null]]])
            ->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();
    }

    /** Leaves Test Mode, then lands on a fresh Production request (as a real browser would). */
    private function leaveTest(): void
    {
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }

    private function enterTest(): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }
}
