<?php

namespace Tests\Feature\Finance;

use App\Actions\Documents\AddInvoiceCorrectionLineAction;
use App\Actions\Documents\CancelInvoiceDraftAction;
use App\Actions\Documents\StartInvoiceCorrectionAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReceivablesService;
use App\Support\Decimal;
use Tests\Support\DocumentTestCase;

/**
 * Correctness of the Finance V1 read-only reporting layer against the exact
 * scenarios the business specified. Every assertion here is against the
 * service layer directly — no HTTP round trip needed to prove the math.
 */
class FinanceMonthlyReportServiceTest extends DocumentTestCase
{
    private function services(): array
    {
        return [app(FinanceMonthlyReportService::class), app(FinanceReceivablesService::class), app(FinanceInvoiceReadModel::class)];
    }

    public function test_draft_sales_order_does_not_count_as_ventes(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-10']);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '500.0000']);

        [$report] = $this->services();
        $period = FinancePeriod::fromMonth('2026-09');

        $this->assertSame(0, Decimal::compare($report->ventesTotal($organization, $period, null), '0.0000'));
    }

    public function test_confirmed_sales_order_counts_using_sale_date(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-10']);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '500.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        [$report] = $this->services();

        $this->assertSame(0, Decimal::compare($report->ventesTotal($organization, FinancePeriod::fromMonth('2026-09'), null), '500.0000'));
        // A different month must not see it, even though created_at is "now".
        $this->assertSame(0, Decimal::compare($report->ventesTotal($organization, FinancePeriod::fromMonth('2026-08'), null), '0.0000'));
    }

    public function test_facturation_uses_invoice_date_not_sale_date(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000'); // sale_date fixed at 2026-05-29
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-02']));

        [$report] = $this->services();

        $this->assertSame(0, Decimal::compare($report->facturationTotal($organization, FinancePeriod::fromMonth('2026-09'), null), '1000.0000'));
        $this->assertSame(0, Decimal::compare($report->facturationTotal($organization, FinancePeriod::fromMonth('2026-05'), null), '0.0000'));
    }

    public function test_encaissements_uses_payment_date_not_invoice_or_sale_date(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-06-01']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '1000.0000', ['payment_date' => '2026-09-20']);

        [$report] = $this->services();

        $this->assertSame(0, Decimal::compare($report->encaissementsTotal($organization, FinancePeriod::fromMonth('2026-09'), null), '1000.0000'));
        $this->assertSame(0, Decimal::compare($report->encaissementsTotal($organization, FinancePeriod::fromMonth('2026-06'), null), '0.0000'));
    }

    /** The exact worked example from the business spec. */
    public function test_september_invoice_with_september_and_october_payments(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '15000.0000');
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-05']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '5000.0000', ['payment_date' => '2026-09-15']);
        $this->recordPayment($owner, $order, $account, '10000.0000', ['payment_date' => '2026-10-10']);

        [$report, $receivables] = $this->services();
        $september = FinancePeriod::fromMonth('2026-09');
        $october = FinancePeriod::fromMonth('2026-10');

        // September.
        $this->assertSame(0, Decimal::compare($report->facturationTotal($organization, $september, null), '15000.0000'));
        $this->assertSame(0, Decimal::compare($report->encaissementsTotal($organization, $september, null), '5000.0000'));
        $this->assertSame(0, Decimal::compare($receivables->totalAsOf($organization, $september->end, null), '10000.0000'));

        // October — the invoice is NOT moved into October.
        $this->assertSame(0, Decimal::compare($report->facturationTotal($organization, $october, null), '0.0000'));
        $this->assertSame(0, Decimal::compare($report->encaissementsTotal($organization, $october, null), '10000.0000'));
        $this->assertSame(0, Decimal::compare($receivables->totalAsOf($organization, $october->end, null), '0.0000'));

        $this->assertSame('2026-09-05', $invoice->invoice_date->toDateString());
    }

    public function test_partial_payment_leaves_invoice_partially_paid(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '400.0000');

        [, , $invoices] = $this->services();
        $row = $invoices->withPaymentSummaries(
            Invoice::where('organization_id', $organization->getKey())->get(),
        )->first();

        $this->assertSame('partial', $row['status']);
        $this->assertSame(0, Decimal::compare($row['outstanding'], '600.0000'));
        $this->assertNull($row['fully_paid_at']);
    }

    public function test_fully_paid_invoice_has_fully_paid_at_and_soldee_status(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-01']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '600.0000', ['payment_date' => '2026-09-10']);
        $this->recordPayment($owner, $order, $account, '400.0000', ['payment_date' => '2026-09-20']);

        [, , $invoices] = $this->services();
        $row = $invoices->withPaymentSummaries(collect([$invoice]))->first();

        $this->assertSame('paid', $row['status']);
        $this->assertSame(0, Decimal::compare($row['outstanding'], '0.0000'));
        $this->assertSame('2026-09-20', $row['fully_paid_at']);
    }

    /**
     * Reachable state: an order is fully paid against its original total,
     * then a correction reduces the current effective invoice's own total
     * below what was already collected (e.g. 1000 paid, corrected down to
     * 400). The receivable must floor at zero — never go negative — and an
     * unrelated invoice's own balance must never be offset by it.
     */
    public function test_receivable_floors_at_zero_and_never_offsets_an_unrelated_invoice_when_overpaid(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture(total: '1000.0000');
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-01']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '1000.0000', ['payment_date' => '2026-09-05']);

        // Simulate the post-correction state directly at the read layer:
        // the effective invoice's own total is now below what the order
        // already collected (paid_amount is derived from the order's
        // payment history, independent of this update). Direct property
        // assignment — Invoice is `guarded = ['*']`, so ->update() would be
        // silently ignored.
        $invoice->total_incl_tax = '400.0000';
        $invoice->save();

        // An unrelated invoice in the SAME organization, with its own
        // genuine, untouched balance.
        $orderTwo = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-02']);
        $this->addCustomLine($owner, $orderTwo, ['unit_price_excl_tax' => '300.0000']);
        $orderTwo = app(ConfirmSalesOrderAction::class)->execute($owner, $orderTwo)->fresh();
        $this->issueInvoice($owner, $this->createInvoice($owner, $orderTwo, ['invoice_date' => '2026-09-02']));

        [, $receivables, $invoices] = $this->services();
        $period = FinancePeriod::fromMonth('2026-09');

        $row = $invoices->withPaymentSummaries(collect([$invoice->fresh()]))->first();
        $this->assertSame(0, Decimal::compare($row['outstanding'], '0.0000'));
        $this->assertSame('paid', $row['status']);

        // The overpaid invoice must contribute exactly 0 — never a negative
        // amount that would reduce the unrelated invoice's own 300 balance.
        $total = $receivables->totalAsOf($organization, $period->end, null);
        $this->assertSame(0, Decimal::compare($total, '300.0000'));
    }

    public function test_reversed_payment_excluded_from_encaissements_and_balance(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $payment = $this->recordPayment($owner, $order, $account, '1000.0000');
        app(ReversePaymentAction::class)->execute($owner, $payment, 'Erreur de caisse');

        [$report, $receivables] = $this->services();
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));

        $this->assertSame(0, Decimal::compare($report->encaissementsTotal($organization, $period, null), '0.0000'));
        $this->assertSame(0, Decimal::compare($receivables->totalAsOf($organization, $period->end, null), '1000.0000'));
    }

    public function test_cancelled_draft_invoice_excluded_from_facturation(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $invoice = $this->createInvoice($owner, $order);
        app(CancelInvoiceDraftAction::class)->execute($owner, $invoice, 'Erreur de saisie');

        [$report] = $this->services();
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));

        $this->assertSame(0, Decimal::compare($report->facturationTotal($organization, $period, null), '0.0000'));
    }

    public function test_superseded_invoice_not_double_counted_and_correction_becomes_effective(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-01']));

        $correction = app(StartInvoiceCorrectionAction::class)->execute($owner, $original, 'Erreur de quantité');
        $taxRate = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Extra', 'EX-1', [
            'default_sale_price' => '100.0000',
            'tax_rate_id' => $taxRate->getKey(),
        ])->variants->first();
        app(AddInvoiceCorrectionLineAction::class)->execute($owner, $correction, [
            'product_variant_id' => $variant->getKey(), 'quantity' => '1.0000', 'discount_type' => 'none',
        ]);
        $correction->refresh();
        $issuedCorrection = $this->issueInvoice($owner, $correction);

        $original->refresh();
        $this->assertSame(InvoiceStatus::Superseded, $original->status);
        $this->assertSame(InvoiceStatus::Issued, $issuedCorrection->status);

        [$report] = $this->services();
        $period = FinancePeriod::fromMonth('2026-09');

        // Facturation counts the correction's own (different) total exactly
        // once — never the superseded original's total on top of it.
        $this->assertSame(0, Decimal::compare($report->facturationTotal($organization, $period, null), $issuedCorrection->total_incl_tax));
        $this->assertNotSame(0, Decimal::compare($issuedCorrection->total_incl_tax, $original->total_incl_tax));
    }

    public function test_payment_before_invoice_does_not_create_a_negative_unrelated_receivable(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);

        // Order A: paid before any invoice exists.
        $orderA = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);
        $this->addCustomLine($owner, $orderA, ['unit_price_excl_tax' => '300.0000']);
        $orderA = app(ConfirmSalesOrderAction::class)->execute($owner, $orderA)->fresh();
        $this->recordPayment($owner, $orderA, $account, $orderA->total_incl_tax, ['payment_date' => '2026-09-05']);

        // Order B: separate customer/order, invoiced and unrelated to A's payment.
        $orderB = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-02']);
        $this->addCustomLine($owner, $orderB, ['unit_price_excl_tax' => '500.0000']);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($owner, $orderB)->fresh();
        $invoiceB = $this->issueInvoice($owner, $this->createInvoice($owner, $orderB, ['invoice_date' => '2026-09-03']));

        [, $receivables] = $this->services();
        $period = FinancePeriod::fromMonth('2026-09');

        // B's receivable must reflect ONLY B's own (zero) payments — A's
        // payment must never reduce it, and must never push it negative.
        $total = $receivables->totalAsOf($organization, $period->end, null);
        $this->assertSame(0, Decimal::compare($total, $invoiceB->total_incl_tax));
        $this->assertSame(0, Decimal::compare($receivables->unappliedPaymentsTotal($organization, $period, null), $orderA->total_incl_tax));
    }

    public function test_multiple_payments_accumulate_correctly_and_cancelled_order_is_never_counted(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '1000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '300.0000');
        $this->recordPayment($owner, $order, $account, '200.0000');
        $this->recordPayment($owner, $order, $account, '500.0000');

        [$report] = $this->services();
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));
        $this->assertSame(0, Decimal::compare($report->encaissementsTotal($organization, $period, null), '1000.0000'));

        // A cancelled order (per existing rules, only possible with zero
        // invoices/payments) must never appear in Ventes.
        $owner2 = User::factory()->create();
        $organization2 = $this->createOrganization($owner2);
        $store2 = $this->createStore($organization2, $owner2);
        $cancelled = $this->createDraftOrder($owner2, $organization2, $store2, null, ['sale_date' => now()->toDateString()]);
        $this->addCustomLine($owner2, $cancelled, ['unit_price_excl_tax' => '750.0000']);
        app(CancelSalesOrderAction::class)->execute($owner2, $cancelled);

        $this->assertSame(0, Decimal::compare($report->ventesTotal($organization2, $period, null), '0.0000'));
    }
}
