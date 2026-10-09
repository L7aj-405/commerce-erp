<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Contracts\PdfGenerator;
use App\Models\CommissionEntry;
use App\Models\CommissionRuleSet;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionReportFilters;
use App\Services\Commissions\CommissionRuleManager;
use App\Services\PermissionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\PosTestCase;

class CommissionDashboardTest extends PosTestCase
{
    // ---------------------------------------------------------------- A. KPIs

    public function test_dashboard_kpis_split_gross_reversals_net_and_status_from_ledger_only(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $order = $this->order($owner, $organization, $store);
        $this->entry($order, ['commission_amount' => '100', 'status' => 'pending']);
        $this->entry($order, ['commission_amount' => '50', 'status' => 'approved']);
        $this->entry($order, ['commission_amount' => '30', 'status' => 'paid']);
        $this->entry($order, ['entry_type' => 'return_reversal', 'commission_amount' => '-20', 'status' => 'pending', 'revenue_ht_snapshot' => '-100', 'cost_total_snapshot' => '-40', 'margin_amount_snapshot' => '-60']);
        $this->entry($order, ['entry_type' => 'cancellation', 'commission_amount' => '-10', 'status' => 'approved', 'revenue_ht_snapshot' => '-100', 'cost_total_snapshot' => '-40', 'margin_amount_snapshot' => '-60']);
        $this->entry($order, ['commission_amount' => '999', 'occurred_at' => '2026-07-31 23:00:00']);

        $this->actingAs($owner)->get(route('finance.commissions.dashboard', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Finance/CommissionDashboard')
                ->where('kpis.gross', '180.0000')
                ->where('kpis.reversals', '-30.0000')
                ->where('kpis.return_reversals', '-20.0000')
                ->where('kpis.cancellations', '-10.0000')
                ->where('kpis.net', '150.0000')
                ->where('kpis.pending', '80.0000')
                ->where('kpis.approved', '40.0000')
                ->where('kpis.paid', '30.0000')
                ->where('kpis.entry_count', 5)
                ->where('statuses.0.status', 'pending')->where('statuses.0.count', 2)
                ->where('statuses.2.amount', '30.0000'));
    }

    public function test_dashboard_defaults_to_current_month_and_resolves_period_presets(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $this->actingAs($owner)->get(route('finance.commissions.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('filters.period', 'this_month')
                ->where('filters.from', now()->startOfMonth()->toDateString())
                ->where('filters.to', now()->endOfMonth()->toDateString()));

        $this->assertSame(
            [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            array_slice(CommissionReportFilters::resolvePeriod('last_month', null, null), 1),
        );
    }

    // ---------------------------------------------------------- B. Margin ratio

    public function test_commission_to_margin_ratio_is_correct_and_undefined_for_zero_or_negative_margin(): void
    {
        $service = app(CommissionDashboardService::class);
        $this->assertSame('10.0000', $service->commissionToMarginRatio('15.0000', '150.0000'));
        $this->assertNull($service->commissionToMarginRatio('15.0000', '0.0000'));
        $this->assertNull($service->commissionToMarginRatio('15.0000', '-20.0000'));
        $this->assertNull($service->marginRate('0.0000', '0.0000'));

        [$owner, $organization, $store] = $this->tenant();
        $order = $this->order($owner, $organization, $store);
        $this->entry($order, ['revenue_ht_snapshot' => '200', 'cost_total_snapshot' => '80', 'margin_amount_snapshot' => '120', 'commission_amount' => '12']);
        $kpis = $service->kpis($organization, $this->filters());
        $this->assertSame('200.0000', $kpis['revenue']);
        $this->assertSame('120.0000', $kpis['margin']);
        $this->assertSame('60.0000', $kpis['margin_rate']);
        $this->assertSame('10.0000', $kpis['commission_to_margin_ratio']);

        // A full reversal brings margin to zero: no misleading 0% / 100%.
        $this->entry($order, ['entry_type' => 'return_reversal', 'revenue_ht_snapshot' => '-200', 'cost_total_snapshot' => '-80', 'margin_amount_snapshot' => '-120', 'commission_amount' => '-12']);
        $kpis = $service->kpis($organization, $this->filters());
        $this->assertNull($kpis['margin_rate']);
        $this->assertNull($kpis['commission_to_margin_ratio']);
    }

    // ------------------------------------------------------ C. Salesperson ranking

    public function test_ranking_groups_by_salesperson_applies_return_reductions_and_flags_unattributed(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $alice = $this->member($organization, 'Alice');
        $bob = $this->member($organization, 'Bob');
        $order = $this->order($owner, $organization, $store);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '100']);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'entry_type' => 'return_reversal', 'commission_amount' => '-70']);
        $this->entry($order, ['salesperson_id' => $bob->id, 'salesperson_name_snapshot' => 'Bob', 'commission_amount' => '50']);
        $this->entry($order, ['salesperson_id' => null, 'salesperson_name_snapshot' => null, 'commission_amount' => '5']);

        $ranking = app(CommissionDashboardService::class)->bySalesperson($organization, $this->filters());
        $this->assertSame(['Bob', 'Alice', 'Non attribuée'], array_column($ranking, 'salesperson_name'));
        $this->assertSame('30.0000', $ranking[1]['net']);
        $this->assertSame('-70.0000', $ranking[1]['reversals']);
        $this->assertTrue($ranking[2]['is_unattributed']);
        $this->assertNull($ranking[2]['salesperson_id']);

        $ascending = app(CommissionDashboardService::class)->bySalesperson($organization, $this->filters(), 'net', 'asc');
        $this->assertSame('Non attribuée', $ascending[0]['salesperson_name']);
    }

    // ---------------------------------------------------------------- D. Filters

    public function test_filters_by_store_salesperson_status_and_type_and_reject_cross_org_ids(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $otherStore = $this->createStore($organization, $owner, 'Second');
        $alice = $this->member($organization, 'Alice');
        $order = $this->order($owner, $organization, $store);
        $this->entry($order, ['commission_amount' => '10']);
        $this->entry($order, ['commission_amount' => '20', 'store_id' => $otherStore->id]);
        $this->entry($order, ['commission_amount' => '40', 'salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'status' => 'paid']);
        $this->entry($order, ['commission_amount' => '-5', 'entry_type' => 'correction']);

        $get = fn (array $query) => $this->actingAs($owner)->get(route('finance.commissions.dashboard', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31', ...$query]));
        $get(['store_id' => $otherStore->id])->assertInertia(fn (Assert $page) => $page->where('kpis.net', '20.0000')->has('storeBreakdown', 1)->where('storeBreakdown.0.store_id', $otherStore->id));
        $get([])->assertInertia(fn (Assert $page) => $page->has('storeBreakdown', 2));
        $get(['salesperson' => $alice->id])->assertInertia(fn (Assert $page) => $page->where('kpis.net', '40.0000'));
        $get(['status' => 'paid'])->assertInertia(fn (Assert $page) => $page->where('kpis.net', '40.0000')->where('kpis.pending', '0.0000'));
        $get(['entry_type' => 'correction'])->assertInertia(fn (Assert $page) => $page->where('kpis.net', '-5.0000')->where('kpis.gross', '0.0000'));
        $get(['salesperson' => 'unattributed'])->assertInertia(fn (Assert $page) => $page->where('kpis.entry_count', 0));

        [$ownerB, $organizationB, $storeB] = $this->tenant();
        $get(['store_id' => $storeB->id])->assertNotFound();
        $get(['salesperson' => $ownerB->id])->assertNotFound();
    }

    // -------------------------------------------------------------- E. Drill-down

    public function test_salesperson_drill_down_is_scoped_paginated_and_hides_costs_without_permission(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $alice = $this->member($organization, 'Alice');
        $bob = $this->member($organization, 'Bob');
        $order = $this->order($owner, $organization, $store);
        for ($i = 0; $i < 30; $i++) {
            $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '1']);
        }
        $this->entry($order, ['salesperson_id' => $bob->id, 'salesperson_name_snapshot' => 'Bob', 'commission_amount' => '500']);
        $query = ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'];

        $this->actingAs($owner)->get(route('finance.commissions.salesperson', ['salesperson' => $alice->id, ...$query]))
            ->assertInertia(fn (Assert $page) => $page->component('Finance/CommissionSalesperson')
                ->where('salesperson.name', 'Alice')
                ->where('kpis.net', '30.0000')
                ->where('entries.total', 30)->has('entries.data', 25)
                ->has('entries.data.0.margin_amount_snapshot'));

        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['commissions.ledger.view']);
        $this->activate($viewer, $organization);
        $response = $this->actingAs($viewer)->get(route('finance.commissions.salesperson', ['salesperson' => $alice->id, 'salesperson_x' => $bob->id, ...$query]));
        $response->assertInertia(fn (Assert $page) => $page->where('kpis.net', '30.0000')->missing('kpis.margin')->missing('entries.data.0.margin_amount_snapshot'));
        $this->assertStringNotContainsString('purchase_cost_snapshot', $response->getContent());
        $this->assertStringNotContainsString('cost_total_snapshot', $response->getContent());

        [$ownerB, $organizationB] = $this->tenant();
        $this->actingAs($ownerB)->get(route('finance.commissions.salesperson', ['salesperson' => $alice->id]))->assertNotFound();
        $this->actingAs($viewer)->get(route('finance.commissions.salesperson', ['salesperson' => $ownerB->id]))->assertNotFound();
    }

    // ---------------------------------------------------------------- F. Own view

    public function test_own_view_only_shows_own_entries_ignores_query_tampering_and_never_leaks_cost(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $alice = $this->member($organization, 'Alice', ['commissions.own.view']);
        $bob = $this->member($organization, 'Bob', ['commissions.own.view']);
        $order = $this->order($owner, $organization, $store);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '24', 'status' => 'approved']);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'entry_type' => 'return_reversal', 'commission_amount' => '-4']);
        $this->entry($order, ['salesperson_id' => $bob->id, 'salesperson_name_snapshot' => 'Bob', 'commission_amount' => '900']);
        $this->activate($alice, $organization);

        $response = $this->actingAs($alice)->get(route('commissions.own', [
            'period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31',
            'salesperson' => $bob->id, 'salesperson_id' => $bob->id, 'store_id' => 999999,
        ]));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Commissions/MyCommissions')
            ->where('summary.net', '20.0000')->where('summary.gross', '24.0000')->where('summary.reversals', '-4.0000')
            ->where('summary.approved', '24.0000')
            ->where('entries.total', 2)
            ->missing('summary.margin')->missing('summary.cost')->missing('summary.revenue')
            ->missing('entries.data.0.margin_amount_snapshot'));
        foreach (['purchase_cost_snapshot', 'cost_total_snapshot', 'margin_amount_snapshot', '900.0000', 'Bob'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }

        $this->actingAs($alice)->get(route('finance.commissions.dashboard'))->assertForbidden();
        $this->actingAs($alice)->get(route('finance.commissions.export.xlsx'))->assertForbidden();

        $plain = $this->member($organization, 'NoAccess');
        $this->activate($plain, $organization);
        $this->actingAs($plain)->get(route('commissions.own'))->assertForbidden();

        // Another organization's salesperson only ever sees their own (empty) scope.
        [$ownerB, $organizationB] = $this->tenant();
        $this->actingAs($ownerB)->get(route('commissions.own', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0)->where('summary.net', '0.0000'));
    }

    // ------------------------------------------------------------------ G. Exports

    public function test_xlsx_export_respects_filters_permissions_cost_visibility_and_is_audited(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $alice = $this->member($organization, 'Alice');
        $order = $this->order($owner, $organization, $store);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '60']);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'entry_type' => 'return_reversal', 'commission_amount' => '-20']);
        $this->entry($order, ['commission_amount' => '7', 'status' => 'paid']);
        $this->entry($order, ['commission_amount' => '5', 'occurred_at' => '2026-09-02 10:00:00']);
        [$ownerB, $organizationB, $storeB] = $this->tenant();
        $this->entry($this->order($ownerB, $organizationB, $storeB), ['commission_amount' => '333']);

        $query = ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'];
        $response = $this->actingAs($owner)->get(route('finance.commissions.export.xlsx', $query))->assertOk();
        $sheets = $this->readXlsx($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['Résumé', 'Commerciaux', 'Écritures'], array_keys($sheets));
        $this->assertCount(4, $sheets['Écritures']); // header + 3 August rows of org A only
        $this->assertContains('Marge', $sheets['Écritures'][0]);
        $this->assertFalse(in_array(333, array_merge(...$sheets['Écritures']), false), 'Other tenant rows must never be exported.');
        $summary = collect($sheets['Commerciaux'])->keyBy(0);
        $this->assertEquals(40.0, $summary['Alice'][array_search('Commission nette', $sheets['Commerciaux'][0], true)]);
        $this->assertEquals(-20.0, $summary['Alice'][array_search('Retours / ajustements', $sheets['Commerciaux'][0], true)]);
        $kpis = collect($sheets['Résumé'])->keyBy(0);
        $this->assertEquals(47.0, $kpis['Commission nette'][1]);
        $this->assertEquals(7.0, $kpis['Payée'][1]);

        $log = DB::table('audit_logs')->where('event', 'commission.report_exported')->latest('id')->first();
        $values = json_decode($log->new_values, true);
        $this->assertSame('xlsx', $values['format']);
        $this->assertSame(3, $values['row_count']);
        $this->assertSame('2026-08-01', $values['filters']['from']);
        $this->assertArrayNotHasKey('rows', $values);

        // Exporter without purchase-cost visibility: no cost/margin columns.
        $exporter = User::factory()->create();
        $this->addOrganizationMember($organization, $exporter, ['commissions.ledger.view', 'commissions.export']);
        $this->activate($exporter, $organization);
        $sheets = $this->readXlsx($this->actingAs($exporter)->get(route('finance.commissions.export.xlsx', $query))->baseResponse->getFile()->getPathname());
        $this->assertNotContains('Coût', $sheets['Écritures'][0]);
        $this->assertNotContains('Marge', $sheets['Écritures'][0]);
        $this->assertNotContains('Marge brute', $sheets['Commerciaux'][0]);

        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['commissions.ledger.view']);
        $this->activate($viewer, $organization);
        $this->actingAs($viewer)->get(route('finance.commissions.export.xlsx', $query))->assertForbidden();
        $this->actingAs($owner)->get(route('finance.commissions.export.xlsx', [...$query, 'store_id' => $storeB->id]))->assertNotFound();
    }

    public function test_pdf_summary_export_contains_kpis_and_salesperson_summary_only(): void
    {
        $captured = new \ArrayObject;
        $this->app->instance(PdfGenerator::class, new class($captured) implements PdfGenerator {
            public function __construct(private \ArrayObject $captured) {}
            public function generate(string $html, array $options = []): string { $this->captured['html'] = $html; $this->captured['options'] = $options; return '%PDF-fake'; }
        });
        [$owner, $organization, $store] = $this->tenant();
        $alice = $this->member($organization, 'Alice');
        $order = $this->order($owner, $organization, $store);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '60', 'product_name_snapshot' => 'Secret line product']);

        $this->actingAs($owner)->get(route('finance.commissions.export.pdf', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Rapport des commissions', $captured['html']);
        $this->assertStringContainsString('Alice', $captured['html']);
        $this->assertStringContainsString('2026-08-01', $captured['html']);
        $this->assertStringNotContainsString('Secret line product', $captured['html']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'commission.report_exported']);
    }

    // ----------------------------------------------------------- H. Notifications

    public function test_approval_and_payment_send_one_aggregate_notification_per_salesperson(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $alice = $this->member($organization, 'Alice', ['commissions.own.view']);
        $bob = $this->member($organization, 'Bob');
        $order = $this->order($owner, $organization, $store);
        $a1 = $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '100']);
        $a2 = $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '140']);
        $b1 = $this->entry($order, ['salesperson_id' => $bob->id, 'salesperson_name_snapshot' => 'Bob', 'commission_amount' => '10']);
        $this->assertSame(0, UserNotification::query()->count(), 'Ledger entry creation must not notify.');

        $this->actingAs($owner)->post(route('finance.commissions.ledger.approve'), ['entry_ids' => [$a1, $a2, $b1]])->assertRedirect();
        $this->assertSame(1, UserNotification::query()->where('user_id', $alice->id)->count());
        $this->assertSame(1, UserNotification::query()->where('user_id', $bob->id)->count());
        $this->assertSame(0, UserNotification::query()->where('user_id', $owner->id)->count());
        $aliceNotice = UserNotification::query()->where('user_id', $alice->id)->sole();
        $this->assertStringContainsString('2 commission(s)', $aliceNotice->message);
        $this->assertStringContainsString('240,00', $aliceNotice->message);
        $this->assertStringNotContainsString('10,00', $aliceNotice->message);
        $this->assertSame('/my-commissions', $aliceNotice->action_url);
        $this->assertNull(UserNotification::query()->where('user_id', $bob->id)->sole()->action_url);

        $this->withFreshAuthentication(1)->actingAs($owner)->post(route('finance.commissions.ledger.mark-paid'), ['entry_ids' => [$a1, $a2]])->assertRedirect();
        $this->assertSame(2, UserNotification::query()->where('user_id', $alice->id)->count());
        $this->assertSame(1, UserNotification::query()->where('user_id', $bob->id)->count());
        $paid = UserNotification::query()->where('user_id', $alice->id)->where('event_type', 'paid')->sole();
        $this->assertStringContainsString('240,00', $paid->message);
    }

    // ---------------------------------------------------------- I. Tier analytics

    public function test_tier_analytics_use_entry_snapshots_not_current_rules(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Tier product', null, ['purchase_price' => '40', 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10');
        $this->rule($owner, $organization, 'V1', '2026-01-01', '10');
        $order = $this->order($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        $v2 = $this->rule($owner, $organization, 'V2', '2026-09-01', '50', activate: false);
        app(CommissionRuleManager::class)->activate($owner, $v2);
        $this->assertSame(CommissionRuleSet::STATUS_ACTIVE, $v2->fresh()->status);

        $entryDate = CommissionEntry::query()->sole()->occurred_at->toDateString();
        $tiers = app(CommissionDashboardService::class)->tierBreakdown($organization, new CommissionReportFilters('custom', $entryDate, $entryDate));
        $this->assertCount(1, $tiers);
        $this->assertSame('V1', $tiers[0]['rule_set']);
        $this->assertSame('10.0000', $tiers[0]['commission_rate']);
        $this->assertSame('30.0000', $tiers[0]['min_margin']);
        $this->assertNull($tiers[0]['max_margin']);
        $this->assertSame('12.0000', $tiers[0]['net']);
        $this->assertSame(1, $tiers[0]['sale_lines']);
    }

    // ------------------------------------------------------- J. Returns by period

    public function test_reversal_counts_in_its_occurrence_period_and_keeps_original_salesperson(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $alice = $this->member($organization, 'Alice');
        $order = $this->order($owner, $organization, $store);
        $sale = $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'commission_amount' => '100', 'sale_date' => '2026-09-10', 'occurred_at' => '2026-09-10 10:00:00']);
        $this->entry($order, ['salesperson_id' => $alice->id, 'salesperson_name_snapshot' => 'Alice', 'entry_type' => 'return_reversal', 'source_entry_id' => $sale,
            'commission_amount' => '-40', 'sale_date' => '2026-09-10', 'occurred_at' => '2026-10-05 09:00:00']);
        $service = app(CommissionDashboardService::class);

        $september = $service->kpis($organization, new CommissionReportFilters('custom', '2026-09-01', '2026-09-30'));
        $october = $service->kpis($organization, new CommissionReportFilters('custom', '2026-10-01', '2026-10-31'));
        $this->assertSame('100.0000', $september['net']);
        $this->assertSame('0.0000', $september['reversals']);
        $this->assertSame('-40.0000', $october['net']);
        $this->assertSame('-40.0000', $october['return_reversals']);

        $ranking = $service->bySalesperson($organization, new CommissionReportFilters('custom', '2026-10-01', '2026-10-31'));
        $this->assertSame($alice->id, $ranking[0]['salesperson_id']);
        $this->assertSame('-40.0000', $ranking[0]['net']);
        $adjustments = $service->adjustments($organization, new CommissionReportFilters('custom', '2026-10-01', '2026-10-31'));
        $this->assertSame('Alice', $adjustments['recent'][0]['salesperson_name']);
        $this->assertSame('2026-09-10', substr($adjustments['recent'][0]['sale_date'], 0, 10));
    }

    // ------------------------------------------------- Health, permissions, tenancy

    public function test_health_counts_are_cheap_and_reconciliation_scan_is_on_demand(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'No cost', null, ['purchase_price' => null, 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '5');
        $order = $this->order($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        $query = ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'];
        $this->actingAs($owner)->get(route('finance.commissions.dashboard', $query))
            ->assertInertia(fn (Assert $page) => $page->where('health.missing_cost', 1)->where('health.has_active_rules', false)->missing('reconciliation'));

        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        $partial = $this->actingAs($owner)->withHeaders([
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Finance/CommissionDashboard', 'X-Inertia-Partial-Data' => 'reconciliation',
        ])->get(route('finance.commissions.dashboard', $query))->assertOk()->json();
        $this->assertSame(['reconciliation'], array_keys(array_intersect_key($partial['props'], ['reconciliation' => 1, 'kpis' => 1])));
        $this->assertSame(1, $partial['props']['reconciliation']['missing_cost']);
    }

    public function test_dashboard_permissions_and_tenant_isolation(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $this->entry($this->order($owner, $organization, $store), ['commission_amount' => '42']);
        $sales = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $sales);
        $this->actingAs($sales)->get(route('finance.commissions.dashboard'))->assertForbidden();
        $this->actingAs($sales)->get(route('commissions.own'))->assertForbidden();

        [$ownerB, $organizationB] = $this->tenant();
        $this->actingAs($ownerB)->get(route('finance.commissions.dashboard', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertInertia(fn (Assert $page) => $page->where('kpis.net', '0.0000')->where('ranking', []));

        app(PermissionProvisioner::class)->provisionOrganization($organization);
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $this->assertTrue($admin->permissions()->where('key', 'commissions.export')->exists());
        $this->assertFalse($admin->permissions()->where('key', 'commissions.own.view')->exists());
        $salesRole = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $this->assertFalse($salesRole->permissions()->where('key', 'commissions.own.view')->exists());
        $custom = $this->createRole($organization, ['commissions.ledger.view'], 'Existing custom');
        app(PermissionProvisioner::class)->provisionOrganization($organization);
        $this->assertSame(['commissions.ledger.view'], $custom->permissions()->pluck('key')->all());
    }

    public function test_dashboard_is_read_only_and_does_not_mutate_ledger(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $id = $this->entry($this->order($owner, $organization, $store), ['commission_amount' => '42']);
        $before = DB::table('commission_entries')->where('id', $id)->first();
        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($owner)->get(route('finance.commissions.dashboard', ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk();
        $this->actingAs($owner)->get(route('finance.commissions.salesperson', ['salesperson' => $owner->id]))->assertOk();

        $this->assertEquals($before, DB::table('commission_entries')->where('id', $id)->first());
        $this->assertSame(1, DB::table('commission_entries')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count(), 'Viewing must not write audit noise.');
    }

    // ------------------------------------------------------------------- helpers

    private function filters(): CommissionReportFilters
    {
        return new CommissionReportFilters('custom', '2026-08-01', '2026-08-31');
    }

    /** Insert a ledger row directly (fixture only) to control dates / statuses precisely. */
    private function entry(SalesOrder $order, array $overrides = []): int
    {
        return DB::table('commission_entries')->insertGetId(array_merge([
            'organization_id' => $order->organization_id,
            'store_id' => $order->store_id,
            'salesperson_id' => $order->salesperson_id,
            'salesperson_name_snapshot' => $order->salesperson_name_snapshot,
            'sales_order_id' => $order->id,
            'entry_type' => 'sale',
            'status' => 'pending',
            'source_key' => 'test:'.Str::uuid(),
            'product_name_snapshot' => 'Product',
            'quantity_snapshot' => '1.0000',
            'revenue_ht_snapshot' => '100.0000',
            'purchase_cost_snapshot' => '40.0000',
            'cost_total_snapshot' => '40.0000',
            'margin_amount_snapshot' => '60.0000',
            'margin_rate_snapshot' => '60.0000',
            'commission_rate_snapshot' => '10.0000',
            'commission_amount' => '6.0000',
            'commission_rule_set_name_snapshot' => 'V1',
            'rule_min_margin_snapshot' => '30.0000',
            'rule_max_margin_snapshot' => null,
            'sale_date' => '2026-08-24',
            'occurred_at' => '2026-08-24 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function order(User $owner, Organization $organization, Store $store): SalesOrder
    {
        return $this->createDraftOrder($owner, $organization, $store);
    }

    private function member(Organization $organization, string $name, array $permissions = []): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->addOrganizationMember($organization, $user, $permissions);

        return $user;
    }

    private function rule(User $owner, Organization $organization, string $name, string $from, string $topRate, bool $activate = true): CommissionRuleSet
    {
        $set = app(CommissionRuleManager::class)->create($owner, $organization, ['name' => $name, 'effective_from' => $from], [
            ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
            ['min_margin_rate' => '10', 'max_margin_rate' => '30', 'commission_rate' => '5'],
            ['min_margin_rate' => '30', 'max_margin_rate' => null, 'commission_rate' => $topRate],
        ]);

        return $activate ? app(CommissionRuleManager::class)->activate($owner, $set) : $set;
    }

    private function tenant(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store];
    }

    /** @return array<string, list<list<mixed>>> */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $sheets;
    }
}
