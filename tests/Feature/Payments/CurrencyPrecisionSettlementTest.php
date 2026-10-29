<?php

namespace Tests\Feature\Payments;

use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Enums\InvoiceStatus;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

/**
 * Payment completion is decided at the order currency's precision (MAD: 2
 * decimals) while amounts stay exact at 4 decimals: a 0.0001 MAD residue
 * settles the order, a genuine 0.01 MAD does not.
 */
class CurrencyPrecisionSettlementTest extends DocumentTestCase
{
    public function test_sub_centime_residue_order_is_fully_paid_by_transfer_and_card_and_can_be_invoiced(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '13306.0001');
        $this->assertSame('13306.0001', (string) $order->total_incl_tax);
        $bank = $this->createFinancialAccount($organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
        $tpe = $this->createFinancialAccount($organization, ['type' => 'card_clearing', 'name' => 'TPE', 'code' => 'TPE']);

        $this->recordPayment($owner, $order, $bank, '10000.0000', ['method' => 'bank_transfer']);
        $this->assertSame('partially_paid', $order->fresh()->payment_status->value);
        $this->assertSame('3306.0001', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));

        $this->recordPayment($owner, $order, $tpe, '3306.0000', ['method' => 'card']);

        $summary = app(SalesOrderPaymentCalculator::class)->summary($order->fresh());
        $this->assertSame('13306.0000', $summary['paid']);
        $this->assertSame('0.0000', $summary['remaining']);
        $this->assertSame('paid', $summary['status']);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->assertSame('13306.0001', (string) $order->fresh()->total_incl_tax, 'The stored 4-decimal total is untouched.');

        $invoice = app(IssueInvoiceAction::class)->execute($owner, $this->createInvoice($owner, $order->fresh()))->fresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame(2, Payment::query()->count(), 'No balancing 0.0001 payment is created.');
    }

    public function test_a_fake_sub_centime_payment_cannot_be_posted_on_a_settled_order(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '13306.0001');
        $bank = $this->createFinancialAccount($organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
        $this->recordPayment($owner, $order, $bank, '13306.0000', ['method' => 'bank_transfer']);

        try {
            $this->recordPayment($owner, $order, $bank, '0.0001', ['method' => 'bank_transfer']);
            $this->fail('Expected the 0.0001 payment to be rejected as an overpayment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_a_genuine_centime_outstanding_keeps_the_order_partially_paid_and_blocks_the_invoice(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '13306.0100');
        $bank = $this->createFinancialAccount($organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
        $this->recordPayment($owner, $order, $bank, '13306.0000', ['method' => 'bank_transfer']);

        $this->assertSame('partially_paid', $order->fresh()->payment_status->value);
        $this->assertSame('0.0100', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));
        $draft = $this->createInvoice($owner, $order->fresh());
        try {
            app(IssueInvoiceAction::class)->execute($owner, $draft);
            $this->fail('Expected invoice issuance to stay blocked by a real 0.01 MAD outstanding.');
        } catch (ValidationException) {
            $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
            $this->assertNull($draft->fresh()->invoice_number);
        }

        $this->recordPayment($owner, $order, $bank, '0.0100', ['method' => 'bank_transfer']);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_overpayment_is_still_rejected_at_the_exact_outstanding(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '13306.0001');
        $bank = $this->createFinancialAccount($organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
        $this->recordPayment($owner, $order, $bank, '10000.0000', ['method' => 'bank_transfer']);

        foreach (['3306.0100', '3306.0002', '5000.0000'] as $amount) {
            try {
                $this->recordPayment($owner, $order, $bank, $amount, ['method' => 'bank_transfer']);
                $this->fail("Expected {$amount} to be rejected as an overpayment.");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('partially_paid', $order->fresh()->payment_status->value);
    }

    public function test_partial_split_payments_still_work_normally(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '13306.0001');
        $bank = $this->createFinancialAccount($organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
        $tpe = $this->createFinancialAccount($organization, ['type' => 'card_clearing', 'name' => 'TPE', 'code' => 'TPE']);

        app(RecordSalesOrderPaymentsAction::class)->execute($owner, $order, [
            ['method' => 'bank_transfer', 'financial_account_id' => $bank->id, 'amount' => '5000.0000', 'payment_date' => now()->toDateString()],
            ['method' => 'card', 'financial_account_id' => $tpe->id, 'amount' => '1000.5000', 'payment_date' => now()->toDateString()],
        ], (string) Str::uuid());

        $summary = app(SalesOrderPaymentCalculator::class)->summary($order->fresh());
        $this->assertSame('6000.5000', $summary['paid']);
        $this->assertSame('7305.5001', $summary['remaining']);
        $this->assertSame('partially_paid', $order->fresh()->payment_status->value);
    }

    public function test_repair_command_rederives_a_stale_stored_status_without_touching_payments(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '13306.0001');
        $bank = $this->createFinancialAccount($organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
        $this->recordPayment($owner, $order, $bank, '10000.0000', ['method' => 'bank_transfer']);
        $this->recordPayment($owner, $order, $bank, '3306.0000', ['method' => 'bank_transfer']);
        // Simulate the status persisted by the previous (exact 4-decimal) rule.
        SalesOrder::query()->withoutGlobalScopes()->whereKey($order->id)->update(['payment_status' => 'partially_paid']);
        $paymentsBefore = Payment::query()->orderBy('id')->get(['id', 'amount', 'status'])->toArray();

        $this->artisan('sales:recalculate-payment-status', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('partially_paid', $order->fresh()->payment_status->value, 'A dry run saves nothing.');

        $this->artisan('sales:recalculate-payment-status')->expectsOutputToContain('1 order(s) updated')->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->artisan('sales:recalculate-payment-status')->expectsOutputToContain('0 order(s) updated')->assertSuccessful();

        $this->assertSame($paymentsBefore, Payment::query()->orderBy('id')->get(['id', 'amount', 'status'])->toArray());
        $this->assertSame('13306.0001', (string) $order->fresh()->total_incl_tax);
    }
}
