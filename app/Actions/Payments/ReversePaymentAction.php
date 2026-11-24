<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\AuthorizesPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceClosedPeriodGuard;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReversePaymentAction
{
    use AuthorizesPaymentAction;

    public function __construct(
        private readonly SalesOrderPaymentCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly FinanceClosedPeriodGuard $periods,
    ) {}

    public function execute(User $actor, Payment $payment, string $reason): Payment
    {
        $first = $payment->allocations()->with('salesOrder')->first();
        if ($first !== null) {
            $this->authorizePayment($actor, $first->salesOrder, 'payments.reverse');
        } else {
            // Finance clients: a fully UNALLOCATED customer advance has no order to authorize from; it is authorized
            // against its own store with the same permission and tenant rules. Any other payment without an allocation stays a 404.
            abort_unless($payment->customer_id !== null, 404);
            abort_if(app(SandboxContext::class)->isRestricted(), 404);
            abort_unless($actor->active_organization_id === $payment->organization_id && $actor->active_store_id === $payment->store_id
                && $payment->store->memberships()->where('user_id', $actor->getKey())->exists()
                && $actor->hasPermission($payment->organization_id, 'payments.reverse'), 403);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Un motif d’annulation est obligatoire.']);
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
                throw ValidationException::withMessages(['payment' => 'Seul un paiement comptabilisé peut être annulé.']);
            }
            if ($payment->refunds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'payment' => 'Un paiement déjà remboursé ne peut pas être annulé : les deux mouvements financiers restent historiques.',
                ]);
            }
            // A reversal removes the payment from its own month (it has no
            // reversal date of its own): refused while that month is closed.
            $this->periods->assertOpen($payment->organization_id, $payment->payment_date, 'payment');

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
