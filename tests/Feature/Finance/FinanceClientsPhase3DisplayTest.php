<?php

namespace Tests\Feature\Finance;

use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

/** Finance clients Phase 3: FCUX-01 (net trend = KPI), FCUX-02 (HT net), FCUX-04 (global counters), FCUX-11 (settled by credit note). */
class FinanceClientsPhase3DisplayTest extends DocumentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    private FinancialAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->customer = $this->createCustomer($this->org, 'Client A', ['company_name' => 'Client A SARL', 'tax_identifier' => 'ICE-A']);
        $this->cash = $this->createFinancialAccount($this->org, ['name' => 'Caisse', 'code' => 'CAISSE']);
        $this->activate($this->owner, $this->org, $this->store);
        $this->actingAs($this->owner);
    }

    /** @return array{0: SalesOrder, 1: Invoice} an invoiced, fulfilled order of $quantity units at 100.00 HT */
    private function invoicedOrder(string $quantity): array
    {
        $settings = $this->org->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080,
            'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $this->org->settings = $settings;
        $this->org->save();
        $warehouse = $this->createWarehouse($this->org);
        $variant = $this->createProduct($this->org, 'Produit', 'P3-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($this->owner, $this->org, $warehouse, $variant, '10.0000');
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => now()->toDateString()]);
        $this->addCatalogLine($this->owner, $order, $variant, $warehouse, ['quantity' => $quantity]);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $order = app(FulfillSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        DB::table('sales_orders')->where('id', $order->getKey())->update(['pos_warehouse_id' => $warehouse->getKey()]);
        $order = $order->fresh();
        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order));

        return [$order->fresh(), $invoice];
    }

    private function receiveReturn(SalesOrder $order, string $quantity): void
    {
        $return = app(CreateCustomerReturnAction::class)->execute($this->owner, $order->fresh(),
            [['sales_order_line_id' => $order->lines()->first()->getKey(), 'quantity' => $quantity]], 'Retour client', 'restock', (string) Str::uuid());
        app(ReceiveCustomerReturnAction::class)->execute($this->owner, $return);
    }

    private function row(Invoice $invoice): array
    {
        return app(FinanceInvoiceReadModel::class)->withPaymentSummaries([$invoice->fresh()])->first();
    }

    public function test_invoice_fully_compensated_by_credit_notes_is_settled_by_credit_note_not_unpaid_nor_overdue(): void
    {
        [$order, $invoice] = $this->invoicedOrder('2.0000');
        DB::table('invoices')->where('id', $invoice->id)->update(['due_date' => now()->subDays(10)->toDateString()]);
        $this->assertSame('unpaid', $this->row($invoice)['settlement_status']);
        $this->assertTrue($this->row($invoice)['is_overdue']);

        $this->receiveReturn($order, '2.0000');
        $row = $this->row($invoice);
        $this->assertSame('0.0000', $row['outstanding']);
        $this->assertSame('0.0000', $row['paid_amount'], 'a credit note is never recorded as a cash payment');
        $this->assertSame('settled_by_credit_note', $row['settlement_status']);
        $this->assertSame('unpaid', $row['status'], 'the existing payment status is unchanged');
        $this->assertFalse($row['is_overdue'], 'zero-balance invoices are excluded from overdue');
        $this->assertSame(0, DB::table('payments')->where('organization_id', $this->org->id)->where('status', 'posted')->count());
    }

    public function test_mixed_payment_and_credit_note_settlement_and_partial_cases(): void
    {
        [$order, $invoice] = $this->invoicedOrder('2.0000');
        $this->recordPayment($this->owner, $order->fresh(), $this->cash, '50.0000');
        $this->assertSame('partial', $this->row($invoice)['settlement_status']);
        $this->receiveReturn($order, '1.0000');          // credit note 100 -> 200 - 100 - 50 = 50 left
        $this->assertSame('partial', $this->row($invoice)['settlement_status']);
        $this->recordPayment($this->owner, $order->fresh(), $this->cash, '50.0000');
        $row = $this->row($invoice);
        $this->assertSame('0.0000', $row['outstanding']);
        $this->assertSame('settled_mixed', $row['settlement_status']);
    }

    public function test_invoice_ht_net_plus_vat_equals_ttc_and_gross_and_discount_stay_available(): void
    {
        [, $invoice] = $this->invoicedOrder('1.0000');
        // Presentation fixture only (the audited invoice 2/2026 amounts): the read model must show HT net, never the pre-discount gross.
        DB::table('invoices')->where('id', $invoice->id)->update(['subtotal_excl_tax' => '124.1700', 'discount_total' => '74.1700', 'tax_total' => '10.0000', 'total_incl_tax' => '60.0000']);
        $row = $this->row($invoice);
        $this->assertSame('50.0000', $row['net_excl_tax']);
        $this->assertSame('124.1700', $row['subtotal_excl_tax']);
        $this->assertSame('74.1700', $row['discount_total']);
        $this->assertSame($row['total_incl_tax'] + 0, ($row['net_excl_tax'] + $row['tax_total']) + 0);
        $this->assertSame('60.0000', $row['total_incl_tax']);
    }

    public function test_net_sales_trend_uses_the_same_definition_as_the_kpi(): void
    {
        [$order] = $this->invoicedOrder('2.0000');       // 240 TTC sold
        $this->receiveReturn($order, '1.0000');          // 120 TTC returned
        $period = FinancePeriod::current($this->org);
        $report = app(FinanceMonthlyReportService::class);
        $gross = $report->ventesTotal($this->org, $period, null);
        $net = $report->ventesNettesTotal($this->org, $period, null);
        $this->assertNotSame($gross, $net, 'fixture must produce a difference between gross and net');

        $props = $this->get(route('finance.clients.overview'))->assertOk()->viewData('page')['props'];
        $this->assertSame($net, $props['metrics']['sales']);
        $this->assertSame($net, $props['dashboard']['flows']['sales_ttc_net']);
        $this->assertSame($net, collect($props['dashboard']['trend'])->last()['sales'], 'trend and KPI reconcile for the same period and store');
        $this->assertSame($net, $report->situation($this->org, $period, null)['ventes_nettes']);
    }

    public function test_invoice_counters_are_global_not_tied_to_the_active_tab(): void
    {
        [$order] = $this->invoicedOrder('2.0000');
        $this->receiveReturn($order, '1.0000');
        foreach (['invoices', 'credit_notes', 'unbilled', 'summary'] as $section) {
            $this->get(route('finance.clients.invoicing', ['section' => $section]))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('Finance/Facturation')->where('counts.invoices', 1)->where('counts.credit_notes', 1));
        }
        $rows = $this->get(route('finance.clients.invoicing', ['section' => 'credit_notes']))->viewData('page')['props'];
        $this->assertSame('0', (string) $rows['invoices']['total'], 'the other tab does not load invoice rows - the counter must not rely on them');
        $note = $rows['creditNotes']['data'][0];
        $this->assertSame(bcsub($note['subtotal_excl_tax'], $note['discount_total'], 4), $note['net_excl_tax']);
    }

    public function test_document_open_carries_a_safe_finance_return_target_and_drops_unsafe_ones(): void
    {
        [, $invoice] = $this->invoicedOrder('1.0000');
        $from = '/finance/clients/comptes/'.$this->customer->id.'?section=invoices';

        $location = $this->post(route('documents.open', ['type' => 'invoice', 'id' => $invoice->id]), ['fc_return' => $from])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('fc_return='.rawurlencode($from), $location);
        $this->assertStringContainsString('/invoices/'.$invoice->id, $location);

        foreach (['https://evil.test/finance/clients/x', '//evil.test/finance/clients/', '/sales/orders/1'] as $bad) {
            $this->assertStringNotContainsString('fc_return', $this->post(route('documents.open', ['type' => 'invoice', 'id' => $invoice->id]), ['fc_return' => $bad])->assertRedirect()->headers->get('Location'));
        }
    }
}
