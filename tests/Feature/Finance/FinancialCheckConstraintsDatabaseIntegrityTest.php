<?php

namespace Tests\Feature\Finance;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Payments\RefundPaymentAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentTestCase;

/**
 * Phase 1 Step 7 (F-05 / S-06): the database itself refuses non-positive
 * collections / allocations / refunds and negative invoice totals. Each rejection
 * test bypasses the application layer (raw query builder) on purpose.
 */
class FinancialCheckConstraintsDatabaseIntegrityTest extends PaymentTestCase
{
    public function test_payment_amount_must_be_strictly_positive(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');

        foreach (['0.0000', '-5.0000'] as $bad) {
            $this->assertRejected(fn () => DB::table('payments')->where('id', $payment->id)->update(['amount' => $bad]));
        }

        $this->assertRejected(fn () => $this->insertPayment($payment, 'PAY-ZERO', '0.0000'));
        $this->assertRejected(fn () => $this->insertPayment($payment, 'PAY-NEG', '-1.0000'));
        $this->assertSame('10.0000', (string) $payment->fresh()->amount);

        $this->insertPayment($payment, 'PAY-OK', '0.0001');
        $this->assertTrue(DB::table('payments')->where('payment_number', 'PAY-OK')->exists());
    }

    public function test_allocation_amount_must_be_strictly_positive(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        $allocationId = DB::table('payment_allocations')->where('payment_id', $payment->id)->value('id');

        foreach (['0.0000', '-0.0001'] as $bad) {
            $this->assertRejected(fn () => DB::table('payment_allocations')->where('id', $allocationId)->update(['amount' => $bad]));
        }

        $this->assertEquals(10, DB::table('payment_allocations')->where('id', $allocationId)->value('amount'));
    }

    public function test_refund_amount_must_be_strictly_positive(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        $refund = app(RefundPaymentAction::class)->execute($owner, $payment, $order, 'Erreur de saisie');

        foreach (['0.0000', '-10.0000'] as $bad) {
            $this->assertRejected(fn () => DB::table('payment_refunds')->where('id', $refund->id)->update(['amount' => $bad]));
        }

        $this->assertSame('10.0000', (string) $refund->fresh()->amount);
    }

    public function test_invoice_total_cannot_be_negative_but_zero_is_allowed(): void
    {
        [$owner, , , $order] = $this->paymentFixture();
        $invoice = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order);

        $this->assertRejected(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total_incl_tax' => '-0.0100']));

        DB::table('invoices')->where('id', $invoice->id)->update(['total_incl_tax' => '0.0000']);
        $this->assertSame('0.0000', (string) $invoice->fresh()->total_incl_tax);
    }

    private function insertPayment($template, string $number, string $amount): void
    {
        DB::table('payments')->insert([
            'organization_id' => $template->organization_id, 'store_id' => $template->store_id,
            'financial_account_id' => $template->financial_account_id, 'payment_number' => $number,
            'method' => 'cash', 'status' => 'posted', 'amount' => $amount, 'currency_code' => 'MAD',
            'payment_date' => now()->toDateString(), 'operation_sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertRejected(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Expected the database CHECK constraint to reject the write.');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/chk_|check constraint/i', $e->getMessage());
        }
    }
}
