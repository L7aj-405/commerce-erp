<?php

namespace Tests\Feature\Sandbox;

use App\Http\Controllers\PosController;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Sandbox;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sandbox\SandboxInventoryGuard;
use App\Services\Sandbox\SandboxResetException;
use App\Services\Sandbox\SandboxResetExecutor;
use App\Services\Sandbox\SandboxResetPlanner;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\QuotationTestCase;

/** Phase 9 — minimal safety checks for the Sandbox reset. */
class SandboxResetTest extends QuotationTestCase
{
    private const SEQUENCES = ['sales_order_sequences', 'quotation_sequences', 'invoice_sequences', 'delivery_note_sequences',
        'credit_note_sequences', 'payment_sequences', 'payment_refund_sequences', 'customer_return_sequences',
        'customer_exchange_sequences', 'procurement_sequences', 'purchase_order_sequences', 'stock_transfer_sequences'];

    public function test_preview_counts_test_only_and_reset_wipes_test_data_leaving_production_and_master_data_intact(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $this->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $this->posSale($variant, $warehouse, $cash, '1');          // Production sale: PAY-000001, stock 5 -> 4
        $this->createQuotation($owner, $org, $store);              // Production quotation

        $this->enterTest();
        $this->posSale($variant, $warehouse, $cash, '2');          // Test sale: order, lines, payment, stock, fulfillment
        $quote = $this->createQuotation($owner, $org, $store);     // Test quotation + line
        $this->addCatalogQuotationLine($owner, $quote, $variant);
        $sandbox = Sandbox::query()->sole();

        $preview = $this->get('/test-mode/reset')->assertOk()->viewData('page')['props']['preview'];
        $items = collect($preview['domains'])->flatMap(fn ($d) => $d['items'])->pluck('count', 'key');
        // Test rows only: Production order / quotation / payment are not counted.
        $this->assertSame(1, $items['sales_orders']);
        $this->assertSame(1, $items['quotations']);
        $this->assertSame(1, $items['quotation_lines']);
        $this->assertSame(1, $items['payments']);
        $this->assertSame(DB::table('sandbox_inventory_movements')->count(), $items['inventory_movements']);
        $this->assertGreaterThan(0, $items['inventory_balances']);
        $this->assertSame(2, DB::table('sales_orders')->count());
        $this->assertSame($preview['total'], array_sum($items->all()));

        $production = $this->productionSnapshot($org);
        $master = $this->masterSnapshot($org);
        $sequences = $this->sequenceSnapshot();

        // Wrong phrase / stale preview: refused, nothing deleted, no success state.
        $this->from('/test-mode/reset')->post('/test-mode/reset', ['confirmation' => 'reset test', 'fingerprint' => $preview['fingerprint']])
            ->assertRedirect('/test-mode/reset')->assertSessionHasErrors('confirmation')->assertSessionMissing('success');
        $this->from('/test-mode/reset')->post('/test-mode/reset', ['confirmation' => 'RESET TEST', 'fingerprint' => str_repeat('a', 64)])
            ->assertRedirect('/test-mode/reset')->assertSessionHasErrors('reset')->assertSessionMissing('success');
        $this->assertSame($preview['total'], array_sum(app(SandboxResetPlanner::class)->counts($sandbox)));

        $this->post('/test-mode/reset', ['confirmation' => 'RESET TEST', 'fingerprint' => $preview['fingerprint']])
            ->assertRedirect('/test-mode')->assertSessionHasNoErrors()->assertSessionHas('success');

        $sandbox->refresh();
        $this->assertSame(0, array_sum(app(SandboxResetPlanner::class)->counts($sandbox)));
        foreach (app(SandboxResetPlanner::class)->tables() as $table) {
            if (str_starts_with($table, 'sandbox_')) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        }
        $this->assertSame(2, $sandbox->generation);
        $this->assertNull($sandbox->inventory_initialized_at);
        $this->assertNotNull($sandbox->reset_at);
        $this->assertEquals($production, $this->productionSnapshot($org));
        $this->assertEquals($master, $this->masterSnapshot($org));
        $this->assertEquals($sequences, $this->sequenceSnapshot());

        $audit = DB::table('audit_logs')->where('event', 'sandbox.reset')->sole();
        $this->assertSame($owner->id, (int) $audit->actor_id);
        $this->assertSame($org->id, (int) $audit->organization_id);
        $this->assertSame(1, json_decode($audit->old_values, true)['generation']);
        $new = json_decode($audit->new_values, true);
        $this->assertSame(2, $new['generation']);
        $this->assertSame($preview['total'], $new['deleted_total']);
        $this->assertSame(1, $new['deleted']['sales_orders']);

        // The acting session follows into generation 2; Test stock is re-snapshotted from Production on next use.
        $props = $this->get('/test-mode')->assertOk()->viewData('page')['props'];
        $this->assertSame(2, $props['current']['generation']);
        $this->assertNotNull($sandbox->fresh()->inventory_initialized_at);
        $this->assertSame('4.0000', Decimal::normalize((string) DB::table('sandbox_inventory_balances')->value('on_hand')));
        // Test numbering stays monotonic across generations (no reissued TEST- reference).
        $this->posSale($variant, $warehouse, $cash, '1');
        $this->assertSame('TEST-PAY-000002', DB::table('sandbox_payments')->value('payment_number'));
    }

    public function test_cross_organization_permission_and_stale_sessions_are_refused(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $this->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $this->enterTest();
        $this->posSale($variant, $warehouse, $cash, '1');
        $sandboxA = Sandbox::query()->sole();
        $countsA = app(SandboxResetPlanner::class)->counts($sandboxA);
        $this->leaveTest();

        $other = User::factory()->create();
        $otherOrg = $this->createOrganization($other, 'Other');
        $otherStore = $this->createStore($otherOrg, $other);
        $this->activate($other, $otherOrg, $otherStore);
        $this->actingAs($other);
        $this->enterTest();
        $sandboxB = Sandbox::query()->where('organization_id', $otherOrg->id)->sole();
        $preview = $this->get('/test-mode/reset')->assertOk()->viewData('page')['props']['preview'];
        $this->assertSame($sandboxB->id, $preview['sandbox']['id']);
        // No request field can address another sandbox / organization.
        $this->post('/test-mode/reset', ['confirmation' => 'RESET TEST', 'fingerprint' => $preview['fingerprint'], 'sandbox_id' => $sandboxA->id])
            ->assertSessionHasErrors('sandbox_id');
        $this->post('/test-mode/reset', ['confirmation' => 'RESET TEST', 'fingerprint' => $preview['fingerprint']])->assertRedirect('/test-mode');
        $this->assertSame(2, $sandboxB->fresh()->generation);
        $this->assertSame(1, $sandboxA->fresh()->generation);
        $this->assertSame($countsA, app(SandboxResetPlanner::class)->counts($sandboxA));

        // Direct executor call with a foreign sandbox: refused before any lock or delete.
        try {
            app(SandboxResetExecutor::class)->execute($otherOrg, $sandboxA, $other, app(SandboxResetPlanner::class)->preview($sandboxA)['fingerprint']);
            $this->fail('Cross-organization reset was accepted.');
        } catch (SandboxResetException $e) {
            $this->assertSame('forbidden', $e->reason);
        }
        $this->assertSame($countsA, app(SandboxResetPlanner::class)->counts($sandboxA));

        // A member without sandbox.manage cannot reach the flow.
        $member = User::factory()->create();
        $this->addOrganizationMember($org, $member, ['sandbox.access', 'sandbox.switch']);
        $this->addStoreMember($store, $member);
        $this->activate($member, $org, $store);
        $this->actingAs($member);
        $this->get('/test-mode/reset')->assertForbidden();
        $this->post('/test-mode/reset', ['confirmation' => 'RESET TEST', 'fingerprint' => str_repeat('a', 64)])->assertForbidden();
        $this->assertSame(1, $sandboxA->fresh()->generation);
    }

    public function test_unexpected_dependency_on_test_rows_blocks_and_rolls_back(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $this->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $this->enterTest();
        $this->posSale($variant, $warehouse, $cash, '1');
        $sandbox = Sandbox::query()->sole();
        $order = DB::table('sales_orders')->whereNotNull('sandbox_id')->sole();
        // A table outside the plan (ON DELETE SET NULL) pointing at a Test order.
        DB::table('follow_ups')->insert(['organization_id' => $org->id, 'title' => 'Relance', 'sales_order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        $counts = app(SandboxResetPlanner::class)->counts($sandbox);

        $preview = $this->get('/test-mode/reset')->assertOk()->viewData('page')['props']['preview'];
        $this->from('/test-mode/reset')->post('/test-mode/reset', ['confirmation' => 'RESET TEST', 'fingerprint' => $preview['fingerprint']])
            ->assertRedirect('/test-mode/reset')->assertSessionHasErrors('reset')->assertSessionMissing('success');

        $this->assertSame($counts, app(SandboxResetPlanner::class)->counts($sandbox));
        $this->assertSame(1, $sandbox->fresh()->generation);
        $this->assertNotNull($sandbox->fresh()->inventory_initialized_at);
        $this->assertSame($order->id, (int) DB::table('follow_ups')->value('sales_order_id'));
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'sandbox.reset')->count());
    }

    /** @return array{User, Organization, \App\Models\Store, ProductVariant, Warehouse, \App\Models\FinancialAccount} */
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

    private function posSale(ProductVariant $variant, Warehouse $warehouse, $cash, string $quantity): void
    {
        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->postJson("/pos/drafts/{$cart}/lines", ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
            'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0'])->assertOk();
        $total = (string) SalesOrder::query()->findOrFail($cart)->total_incl_tax;
        $this->post('/pos/sales', ['client_operation_id' => (string) Str::uuid(), 'order_id' => $cart,
            'payments' => [['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => $total, 'cash_received' => $total, 'reference' => null]]])
            ->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();
    }

    private function enterTest(): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }

    private function leaveTest(): void
    {
        $this->post('/test-mode/leave')->assertRedirect();
    }

    /** Production transactional rows, read in full (sanctioned bypass for the Production ledgers). */
    private function productionSnapshot(Organization $org): array
    {
        return SandboxInventoryGuard::bypass(function () use ($org) {
            $rows = fn ($query) => $query->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $out = [];
            foreach (['sales_orders', 'quotations', 'invoice_families', 'invoices', 'delivery_notes', 'credit_notes', 'customer_returns', 'customer_exchanges'] as $table) {
                $out[$table] = $rows(DB::table($table)->where('organization_id', $org->id)->whereNull('sandbox_id'));
            }
            $out['sales_order_lines'] = $rows(DB::table('sales_order_lines')->whereIn('sales_order_id', DB::table('sales_orders')->select('id')->whereNull('sandbox_id')));
            $out['quotation_lines'] = $rows(DB::table('quotation_lines')->whereIn('quotation_id', DB::table('quotations')->select('id')->whereNull('sandbox_id')));
            foreach (['payments', 'payment_allocations', 'payment_refunds', 'commission_entries', 'inventory_balances', 'inventory_movements',
                'inventory_reservations', 'sales_order_inventory_allocations', 'fulfillments', 'fulfillment_items', 'purchase_orders', 'sales_order_procurements'] as $table) {
                $out[$table] = $rows(DB::table($table)->where('organization_id', $org->id));
            }
            $this->assertNotEmpty($out['sales_orders']);
            $this->assertNotEmpty($out['payments']);

            return $out;
        });
    }

    private function masterSnapshot(Organization $org): array
    {
        $out = [];
        foreach (['products', 'product_variants', 'customers', 'suppliers', 'stores', 'warehouses', 'financial_accounts', 'roles', 'commission_rule_sets'] as $table) {
            $out[$table] = DB::table($table)->where('organization_id', $org->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $out['users'] = DB::table('users')->orderBy('id')->pluck('id')->all();
        $out['organization_settings'] = DB::table('organizations')->whereKey($org->id)->value('settings');

        return $out;
    }

    private function sequenceSnapshot(): array
    {
        return SandboxInventoryGuard::bypass(fn () => collect([...self::SEQUENCES, 'sandbox_document_sequences'])->filter(fn ($t) => Schema::hasTable($t))
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->all()])->all());
    }
}
