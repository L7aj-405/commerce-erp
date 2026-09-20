<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\AuthorizesPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReversePaymentAction
{
    use AuthorizesPaymentAction;

    public function __construct(private readonly SalesOrderPaymentCalculator $calculator, private readonly AuditLogger $audit) {}

    public function execute(User $actor, Payment $payment, string $reason): Payment
    {
        $order = $payment->allocations()->with('salesOrder')->firstOrFail()->salesOrder;
        $this->authorizePayment($actor, $order, 'payments.reverse');

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reversal reason is required.']);
        }

        return DB::transaction(function () use ($actor, $payment, $reason) {
            // Lock Orders before Payment, matching cancellation/refund lock order
            // and avoiding a payment↔order deadlock under concurrent requests.
            $orderIds = PaymentAllocation::query()
                ->where('organization_id', $payment->organization_id)
                ->where('payment_id', $payment->getKey())
                ->orderBy('sales_order_id')
                ->pluck('sales_order_id');
            $orders = SalesOrder::query()
                ->where('organization_id', $payment->organization_id)
                ->whereIn('id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payment = Payment::query()
                ->where('organization_id', $payment->organization_id)
                ->where('store_id', $payment->store_id)
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->with(['allocations', 'refunds'])
                ->firstOrFail();
            if ($payment->status !== PaymentStatus::Posted) {
                throw ValidationException::withMessages(['payment' => 'Only a posted Payment can be reversed.']);
            }
            if ($payment->refunds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'payment' => 'A Payment with a posted refund cannot be reversed; both financial movements must remain historical.',
                ]);
            }

            $payment->status = PaymentStatus::Reversed;
            $payment->reversed_at = now();
            $payment->reversed_by_user_id = $actor->getKey();
            $payment->reversal_reason = $reason;
            $payment->save();

            foreach ($orders as $order) {
                $this->calculator->recalculate($order);
            }

            $orderNumbers = $orders->pluck('order_number')->all();
            $this->audit->record('payment.reversed', $actor, $payment->organization, $payment->store, $payment, oldValues: [
                'status' => PaymentStatus::Posted->value,
            ], newValues: [
                'payment_number' => $payment->payment_number,
                'order_numbers' => $orderNumbers,
                'status' => PaymentStatus::Reversed->value,
                'reason' => $reason,
            ]);

            return $payment->load(['financialAccount:id,name,code,type', 'allocations.salesOrder:id,order_number', 'receivedBy:id,name', 'reversedBy:id,name']);
        });
    }
}
