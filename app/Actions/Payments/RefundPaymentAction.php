<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\AuthorizesPaymentAction;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentRefundNumberGenerator;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record the real outflow which refunds one posted Payment allocation.
 *
 * The original collection remains immutable and posted. The positive amount
 * on this row has outflow semantics because it lives in payment_refunds; no
 * negative Payment convention and no external-processor success is invented.
 */
class RefundPaymentAction
{
    use AuthorizesPaymentAction;

    public function __construct(
        private readonly PaymentRefundNumberGenerator $numbers,
        private readonly SalesOrderPaymentCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Payment $payment, SalesOrder $order, string $reason): PaymentRefund
    {
        $this->authorizePayment($actor, $order, 'payments.reverse');

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A refund reason is required.']);
        }

        return DB::transaction(function () use ($actor, $payment, $order, $reason) {
            $order = SalesOrder::query()
                ->where('organization_id', $order->organization_id)
                ->where('store_id', $order->store_id)
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $payment = Payment::query()
                ->where('organization_id', $order->organization_id)
                ->where('store_id', $order->store_id)
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $allocation = PaymentAllocation::query()
                ->where('organization_id', $order->organization_id)
                ->where('payment_id', $payment->getKey())
                ->where('sales_order_id', $order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = PaymentRefund::query()
                ->where('organization_id', $order->organization_id)
                ->where('payment_id', $payment->getKey())
                ->where('sales_order_id', $order->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            if ($order->status !== SalesOrderStatus::Confirmed || $order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled) {
                throw ValidationException::withMessages([
                    'order' => 'Cancellation refunds are only allowed for confirmed, unfulfilled orders.',
                ]);
            }
            if ($order->invoices()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value])->exists()) {
                throw ValidationException::withMessages([
                    'order' => 'An issued Invoice requires a Credit Note before a refund can be recorded.',
                ]);
            }
            if ($payment->status !== PaymentStatus::Posted) {
                throw ValidationException::withMessages([
                    'payment' => 'Only a posted Payment can be refunded. Reversed entries are not collections.',
                ]);
            }
            if (Decimal::compare($allocation->amount, '0.0000') <= 0) {
                throw ValidationException::withMessages(['payment' => 'The Payment allocation has no refundable amount.']);
            }

            $refund = new PaymentRefund;
            $refund->organization_id = $order->organization_id;
            $refund->store_id = $order->store_id;
            $refund->payment_id = $payment->getKey();
            $refund->sales_order_id = $order->getKey();
            $refund->financial_account_id = $payment->financial_account_id;
            $refund->refund_number = $this->numbers->next($order->organization);
            $refund->method = $payment->method;
            $refund->status = 'posted';
            $refund->amount = $allocation->amount;
            $refund->currency_code = $payment->currency_code;
            $refund->refund_date = now()->toDateString();
            $refund->reason = trim($reason);
            $refund->refunded_by_user_id = $actor->getKey();
            $refund->save();

            $this->calculator->recalculate($order);
            $this->audit->record('payment.refunded', $actor, $order->organization, $order->store, $refund, newValues: [
                'refund_number' => $refund->refund_number,
                'original_payment_id' => $payment->getKey(),
                'original_payment_number' => $payment->payment_number,
                'order_number' => $order->order_number,
                'method' => $payment->method->value,
                'financial_account_id' => $payment->financial_account_id,
                'amount' => $refund->amount,
                'refund_date' => $refund->refund_date->toDateString(),
                'reason' => $refund->reason,
            ]);

            return $refund->load(['payment:id,payment_number', 'financialAccount:id,name,code,type', 'refundedBy:id,name']);
        }, 3);
    }
}
