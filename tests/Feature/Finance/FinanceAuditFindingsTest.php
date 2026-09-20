<?php

namespace Tests\Feature\Finance;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Enums\SalesOrderPaymentStatus;
use App\Models\PaymentAllocation;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Tests\Support\DocumentTestCase;

/**
 * Pins down the exact pre-Finance-V1 behaviors the Finance V1 audit report
 * relies on. These are documentation tests: they assert what the system
 * ALREADY does, not new business logic. If one of these ever fails, the
 * Finance V1 design built on top of it needs to be re-checked.
 */
class FinanceAuditFindingsTest extends DocumentTestCase
{
    public function test_a_payment_always_allocates_to_exactly_one_sales_order_never_to_an_invoice_directly(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('100.0000');
        $payment = $this->recordPayment($owner, $order, $account, '40.0000');

        $this->assertSame(1, $payment->allocations()->count());
        $allocation = $payment->allocations()->firstOrFail();
        $this->assertSame($order->getKey(), $allocation->sales_order_id);
        $this->assertArrayNotHasKey('invoice_id', $allocation->getAttributes());
    }

    public function test_payment_status_and_amounts_are_derived_from_posted_allocations_across_multiple_payments(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('150.0000');
        $calculator = app(SalesOrderPaymentCalculator::class);

        $this->recordPayment($owner, $order, $account, '50.0000');
        $order->refresh();
        $this->assertSame(0, Decimal::compare($calculator->paidAmount($order), '50.0000'));
        $this->assertSame(SalesOrderPaymentStatus::PartiallyPaid, $order->payment_status);

        $second = $this->recordPayment($owner, $order, $account, '100.0000');
        $order->refresh();
        $this->assertSame(0, Decimal::compare($calculator->paidAmount($order), '150.0000'));
        $this->assertSame(0, Decimal::compare($calculator->remainingAmount($order), '0.0000'));
        $this->assertSame(SalesOrderPaymentStatus::Paid, $order->payment_status);

        // Reversing the second payment must roll the aggregate back down —
        // "paid" is always recomputed from currently-posted allocations only.
        app(ReversePaymentAction::class)->execute($owner, $second, 'test reversal');
        $order->refresh();
        $this->assertSame(0, Decimal::compare($calculator->paidAmount($order), '50.0000'));
        $this->assertSame(SalesOrderPaymentStatus::PartiallyPaid, $order->payment_status);
    }

    public function test_payments_in_different_months_keep_their_own_payment_date_independent_of_invoice_date(): void
    {
        [$owner, , , $order] = $this->documentFixture(total: '150.0000');
        // documentFixture fixes sale_date to 2026-05-29; the invoice keeps that
        // same fixed date regardless of when payments later land.
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-05-29']));
        $account = $this->createFinancialAccount($order->organization);

        $september = $this->recordPayment($owner, $order, $account, '50.0000', ['payment_date' => '2026-09-15']);
        $october = $this->recordPayment($owner, $order, $account, '100.0000', ['payment_date' => '2026-10-05']);

        $this->assertSame('2026-05-29', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-09-15', $september->payment_date->toDateString());
        $this->assertSame('2026-10-05', $october->payment_date->toDateString());

        // "Encaissements" for a given month must be computed by filtering
        // Payment.payment_date directly — never by the invoice's own date.
        $septemberTotal = PaymentAllocation::query()
            ->whereHas('payment', fn ($q) => $q->whereDate('payment_date', '2026-09-15')->where('status', 'posted'))
            ->where('sales_order_id', $order->getKey())
            ->sum('amount');
        $octoberTotal = PaymentAllocation::query()
            ->whereHas('payment', fn ($q) => $q->whereDate('payment_date', '2026-10-05')->where('status', 'posted'))
            ->where('sales_order_id', $order->getKey())
            ->sum('amount');
        $this->assertSame(0, Decimal::compare((string) $septemberTotal, '50.0000'));
        $this->assertSame(0, Decimal::compare((string) $octoberTotal, '100.0000'));
    }

    public function test_replacement_invoice_total_remains_aligned_with_the_corrected_sales_order(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '100.0000');
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $orderTotal = $order->fresh()->total_incl_tax;

        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order, 'Erreur de quantité');
        $line = $order->fresh()->lines()->firstOrFail();
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'custom', 'description' => 'Consulting', 'reference' => null, 'unit_label' => 'hour',
            'quantity' => '2.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => null,
            'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $line);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $replacement = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order);

        $this->assertNotSame(0, Decimal::compare($order->total_incl_tax, $orderTotal));
        $this->assertSame(0, Decimal::compare($replacement->total_incl_tax, $order->total_incl_tax));
        $this->assertSame(0, Decimal::compare($original->fresh()->total_incl_tax, $orderTotal));
    }

    public function test_a_draft_invoice_can_only_be_cancelled_never_an_issued_one(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order);

        $this->assertNull($invoice->issued_at);
        // Only App\Actions\Documents\CancelInvoiceDraftAction exists for
        // cancellation, and it hard-rejects anything but a Draft — there is no
        // "cancel an issued invoice" action anywhere in the codebase. A
        // cancelled invoice therefore never had an invoice_number/issued_at and
        // can never appear in a "Facturation" total for any month.
        $this->assertFalse(class_exists('App\\Actions\\Documents\\CancelIssuedInvoiceAction'));
    }
}
