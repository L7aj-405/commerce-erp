<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\CancelSalesOrderAction;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

class PaymentReversalTest extends PaymentTestCase
{
    public function test_reversal_preserves_payment_and_allocation_and_recalculates_projection(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '100.0000');
        $reversed = app(ReversePaymentAction::class)->execute($owner, $payment, 'Entry error');

        $this->assertSame('reversed', $reversed->status->value);
        $this->assertSame('unpaid', $order->fresh()->payment_status->value);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id, 'sales_order_id' => $order->id, 'amount' => 100]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.reversed', 'organization_id' => $organization->id]);
    }

    public function test_reversing_one_of_multiple_payments_changes_paid_to_partial(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $first = $this->recordPayment($owner, $order, $account, '40.0000');
        $this->recordPayment($owner, $order, $account, '60.0000');
        app(ReversePaymentAction::class)->execute($owner, $first, 'Refund handled elsewhere');
        $this->assertSame('partially_paid', $order->fresh()->payment_status->value);
    }

    public function test_double_reversal_is_rejected(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '25.0000');
        app(ReversePaymentAction::class)->execute($owner, $payment, 'Mistake');
        $this->expectException(ValidationException::class);
        app(ReversePaymentAction::class)->execute($owner, $payment->fresh(), 'Again');
    }

    public function test_reversal_requires_a_reason(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '25.0000');
        $this->expectException(ValidationException::class);
        app(ReversePaymentAction::class)->execute($owner, $payment, '  ');
    }

    public function test_paid_confirmed_order_requires_payment_reversal_before_cancellation(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '10.0000');
        $this->expectException(ValidationException::class);
        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Customer request');
    }

    public function test_order_can_be_cancelled_after_all_posted_payments_are_reversed(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        app(ReversePaymentAction::class)->execute($owner, $payment, 'Void before cancellation');
        $cancelled = app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Customer request');
        $this->assertSame('cancelled', $cancelled->status->value);
    }
}
