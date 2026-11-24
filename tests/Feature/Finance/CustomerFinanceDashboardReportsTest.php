<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\ManageCustomerInstrumentAction;
use App\Actions\Finance\Customers\ManageCustomerOpeningBalanceAction;
use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Finance\Customers\SaveCustomerFinancialTermsAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\CustomerAgingReport;
use App\Services\Finance\CustomerFinancialPositionService;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 2 / FC-01 dashboard, FC-08 journal, FC-09 reports: reconciliation between pages and exports. */
class CustomerFinanceDashboardReportsTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private FinancialAccount $account;

    private Customer $a;

    private Customer $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->a = $this->createCustomer($this->org, 'Atlas');
        $this->b = $this->createCustomer($this->org, 'Bravo');
        $this->actingAs($this->owner);
    }

    private function order(Customer $customer, string $total, ?string $saleDate = null): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer, $saleDate ? ['sale_date' => $saleDate] : []);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    private function settle(Customer $customer, string $amount, array $allocations = []): Payment
    {
        return app(RecordCustomerSettlementAction::class)->execute($this->owner, $customer, [
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => $amount, 'payment_date' => now()->toDateString(),
            'allocations' => array_map(fn ($r) => ['sales_order_id' => $r[0]->id, 'amount' => $r[1]], $allocations),
        ], (string) Str::uuid());
    }

    public function test_dashboard_reconciles_with_the_detail_services_for_flows_balances_and_alerts(): void
    {
        $o = $this->order($this->a, '1000.0000');
        $this->order($this->b, '400.0000');
        $this->settle($this->a, '300.0000', [[$o, '300.0000']]);
        $this->settle($this->b, '50.0000');               // advance
        app(SaveCustomerFinancialTermsAction::class)->execute($this->owner, $this->a, ['credit_enabled' => true, 'credit_limit' => '500.0000', 'policy' => 'warn', 'financial_status' => 'normal']);
        app(SaveCustomerFinancialTermsAction::class)->execute($this->owner, $this->b, ['credit_enabled' => false, 'financial_status' => 'blocked', 'policy' => 'warn']);

        $props = $this->get(route('finance.clients.overview'))->assertOk()->viewData('page')['props'];
        $period = FinancePeriod::current($this->org);
        $situation = app(FinanceMonthlyReportService::class)->situation($this->org, $period, null);
        $aging = app(CustomerAgingReport::class)->build($this->org, null, $period->end);
        $d = $props['dashboard'];

        $this->assertSame($situation['encaissements'], $d['flows']['collections_gross']);
        $this->assertSame($situation['net_encaisse'], $d['flows']['collections_net']);
        $this->assertSame($situation['facturation'], $d['flows']['billing_net_ttc']);
        $this->assertSame('350.0000', $d['flows']['collections_gross'], 'allocated 300 + advance 50: cash received in the month');
        $this->assertSame($aging['totals']['MAD']['unbilled'], $d['balances']['by_currency']['MAD']['unbilled']);
        $this->assertSame('1100.0000', $d['balances']['by_currency']['MAD']['unbilled'], '700 + 400: balances of orders, advance not deducted');
        $this->assertSame(['MAD' => '50.0000'], $d['balances']['advances']);
        $this->assertSame(1, $d['credit_alerts']['over_limit']);
        $this->assertSame(1, $d['credit_alerts']['blocked']);
        // The dashboard unbilled total equals the sum of the per-customer positions the accounts page shows.
        $positions = app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$this->a->id, $this->b->id], $this->owner);
        $this->assertSame($d['balances']['by_currency']['MAD']['unbilled'], Decimal::add($positions[$this->a->id]['unbilled'], $positions[$this->b->id]['unbilled']));
        $this->assertCount(6, $d['trend']);
        // Existing headline metrics are unchanged.
        $this->assertSame($situation['ventes_nettes'], $props['metrics']['sales']);
    }

    public function test_journal_lists_customer_events_as_informational_without_double_counting_a_collection(): void
    {
        $o = $this->order($this->a, '400.0000');
        app(ManageCustomerOpeningBalanceAction::class)->record($this->owner, $this->a, ['effective_date' => now()->toDateString(), 'amount' => '90.0000', 'currency_code' => 'MAD', 'reference' => 'REP-9', 'reason' => 'Dette historique']);
        $advance = $this->settle($this->a, '60.0000');
        $instrument = app(ManageCustomerInstrumentAction::class)->receive($this->owner, $this->a, ['type' => 'cheque', 'instrument_number' => 'CHQ-77', 'issuing_bank' => 'BMCE', 'amount' => '400.0000',
            'received_date' => now()->toDateString(), 'financial_account_id' => $this->account->id, 'sales_order_id' => $o->id], (string) Str::uuid());
        $actions = app(ManageCustomerInstrumentAction::class);
        $actions->transition($this->owner, $instrument, 'submitted', now()->toDateString(), null);
        $actions->transition($this->owner, $instrument, 'cleared', now()->toDateString(), null);

        $events = collect($this->get(route('finance.clients.journal'))->assertOk()->viewData('page')['props']['events']);
        $byType = $events->groupBy('type');
        $this->assertCount(1, $byType['opening_balance']);
        $this->assertTrue($byType['opening_balance'][0]['informational']);
        $this->assertEqualsCanonicalizing(['instrument_received', 'instrument_submitted', 'instrument_cleared'], collect($byType->keys())->filter(fn ($k) => str_starts_with($k, 'instrument_'))->values()->all());
        // Money events: the advance (60) and the clearance payment (400). Instrument events carry no money of their own.
        $collections = $events->where('type', 'payment')->whereNull('informational');
        $this->assertSame('460.0000', $collections->reduce(fn ($sum, $e) => Decimal::add($sum, $e['amount']), '0.0000'));
        $this->assertSame('Atlas', $events->firstWhere('number', $advance->payment_number)['customer'], 'an advance names its customer');
        $this->assertSame('Avance non affectée', $events->firstWhere('number', $advance->payment_number)['reference']);

        $filtered = collect($this->get(route('finance.clients.journal', ['type' => 'opening_balance']))->viewData('page')['props']['events']);
        $this->assertCount(1, $filtered);
        $this->assertCount(1, collect($this->get(route('finance.clients.journal', ['search' => 'CHQ-77']))->viewData('page')['props']['events'])->where('type', 'instrument_cleared'));

        $csv = $this->get(route('finance.clients.journal.export', ['format' => 'csv']))->assertOk();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($csv->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertCount($events->count() + 1, $lines, 'export = page events for the same filters');
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_clients.journal_exported']);
        $this->get('/finance/journal')->assertOk();   // legacy URL keeps working
    }

    public function test_profitability_uses_committed_snapshots_nets_returns_and_flags_missing_costs(): void
    {
        $costed = $this->order($this->a, '1000.0000');
        $line = DB::table('sales_order_lines')->where('sales_order_id', $costed->id)->first();
        $taxable = Decimal::normalize((string) $line->taxable_amount);
        DB::table('sales_order_lines')->where('id', $line->id)->update(['cost_status' => 'available', 'purchase_price_snapshot' => '600.0000', 'cost_total_snapshot' => '600.0000',
            'margin_amount_snapshot' => Decimal::subtract($taxable, '600.0000')]);
        $this->order($this->b, '500.0000');   // custom line: no committed cost

        $report = $this->get(route('finance.clients.reports', ['section' => 'profitability', 'month' => $costed->sale_date->format('Y-m')]))->assertOk()->viewData('page')['props']['profitability'];
        $rows = collect($report['rows'])->keyBy('customer');
        $this->assertSame($taxable, $rows['Atlas']['revenue']);
        $this->assertSame('600.0000', $rows['Atlas']['cost']);
        $this->assertSame(Decimal::subtract($taxable, '600.0000'), $rows['Atlas']['margin']);
        $this->assertFalse($rows['Atlas']['incomplete']);
        $this->assertTrue($rows['Bravo']['incomplete']);
        $this->assertSame('0.0000', $rows['Bravo']['covered_revenue']);
        $this->assertNull($rows['Bravo']['margin_rate'], 'no rate is invented without a cost');
        $this->assertSame(1, $report['totals']['incomplete']);

        $csv = $this->get(route('finance.clients.profitability.export', ['format' => 'csv', 'month' => $costed->sale_date->format('Y-m')]))->assertOk();
        $this->assertStringContainsString('Atlas', $csv->streamedContent());
        $this->assertStringContainsString('oui', $csv->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_clients.profitability_exported']);
    }

    public function test_reports_exports_need_finance_export_and_the_hub_lists_every_report(): void
    {
        $this->order($this->a, '100.0000', Carbon::now()->toDateString());
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->org, $viewer, ['finance.view', 'customers.view'], 'active', 'R'.Str::random(4));
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->org, $this->store);
        $this->actingAs($viewer);
        $this->get(route('finance.clients.reports'))->assertOk()->assertInertia(fn ($page) => $page->where('can.export', false));
        foreach (['finance.clients.profitability.export', 'finance.clients.journal.export', 'finance.clients.aging.export', 'finance.clients.accounts.export'] as $route) {
            $this->get(route($route, ['format' => 'csv']))->assertForbidden();
        }
        $this->actingAs($this->owner);
        foreach (['finance.clients.profitability.export', 'finance.clients.journal.export', 'finance.clients.aging.export', 'finance.clients.accounts.export'] as $route) {
            $this->get(route($route, ['format' => 'xlsx']))->assertOk();
        }
    }
}
