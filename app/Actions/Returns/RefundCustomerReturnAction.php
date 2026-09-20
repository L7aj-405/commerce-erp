<?php

namespace App\Actions\Returns;

use App\Enums\PaymentStatus;
use App\Models\CustomerReturn;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentRefundNumberGenerator;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundCustomerReturnAction
{
    public function __construct(private readonly PaymentRefundNumberGenerator $numbers, private readonly SalesOrderPaymentCalculator $payments, private readonly AuditLogger $audit) {}

    public function execute(User $actor, CustomerReturn $customerReturn, Payment $payment, string $amount, string $reason, string $operationId): PaymentRefund
    {
        abort_unless($actor->active_organization_id === $customerReturn->organization_id && $actor->active_store_id === $customerReturn->store_id, 404);
        abort_unless($customerReturn->store()->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->id))->exists(), 404);
        abort_unless($actor->hasPermission($customerReturn->organization_id, 'payments.reverse'), 403);
        $amount = Decimal::positive($amount);
        if (trim($reason) === '') throw ValidationException::withMessages(['reason' => 'Le motif du remboursement est obligatoire.']);

        return DB::transaction(function () use ($actor, $customerReturn, $payment, $amount, $reason, $operationId) {
            $customerReturn = CustomerReturn::query()->where('organization_id', $customerReturn->organization_id)->where('store_id', $customerReturn->store_id)
                ->whereKey($customerReturn->id)->lockForUpdate()->with(['organization', 'store', 'salesOrder', 'creditNotes'])->firstOrFail();
            if ($customerReturn->status !== 'received') throw ValidationException::withMessages(['return' => 'Le retour doit être réceptionné avant le remboursement.']);
            $existing = PaymentRefund::query()->where('organization_id', $customerReturn->organization_id)->where('store_id', $customerReturn->store_id)->where('client_operation_id', $operationId)->first();
            if ($existing) {
                if ($existing->customer_return_id !== $customerReturn->id || $existing->payment_id !== $payment->id || Decimal::compare($existing->amount, $amount) !== 0 || $existing->reason !== trim($reason)) {
                    throw ValidationException::withMessages(['client_operation_id' => 'Cet identifiant d’opération a déjà été utilisé avec un autre remboursement.']);
                }

                return $existing;
            }
            $payment = Payment::query()->where('organization_id', $customerReturn->organization_id)->where('store_id', $customerReturn->store_id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Posted) throw ValidationException::withMessages(['payment' => 'Seul un paiement comptabilisé peut être remboursé.']);
            $allocation = PaymentAllocation::query()->where('organization_id', $customerReturn->organization_id)->where('payment_id', $payment->id)->where('sales_order_id', $customerReturn->sales_order_id)->lockForUpdate()->firstOrFail();
            $returnRefunded = PaymentRefund::query()->where('organization_id', $customerReturn->organization_id)->where('customer_return_id', $customerReturn->id)->where('status', 'posted')->lockForUpdate()->sum('amount');
            $paymentRefunded = PaymentRefund::query()->where('organization_id', $customerReturn->organization_id)->where('payment_id', $payment->id)->where('sales_order_id', $customerReturn->sales_order_id)->where('status', 'posted')->lockForUpdate()->sum('amount');
            if (Decimal::compare($amount, Decimal::subtract($customerReturn->total_incl_tax, (string) $returnRefunded)) > 0) throw ValidationException::withMessages(['amount' => 'Le remboursement dépasse le solde de ce retour.']);
            if (Decimal::compare($amount, Decimal::subtract($allocation->amount, (string) $paymentRefunded)) > 0) throw ValidationException::withMessages(['amount' => 'Le remboursement dépasse le solde du paiement sélectionné.']);
            $refund = new PaymentRefund;
            $refund->organization_id = $customerReturn->organization_id; $refund->store_id = $customerReturn->store_id; $refund->payment_id = $payment->id;
            $refund->sales_order_id = $customerReturn->sales_order_id; $refund->customer_return_id = $customerReturn->id; $refund->client_operation_id = $operationId;
            $refund->financial_account_id = $payment->financial_account_id; $refund->refund_number = $this->numbers->next($customerReturn->organization);
            $refund->method = $payment->method; $refund->status = 'posted'; $refund->amount = $amount; $refund->currency_code = $payment->currency_code;
            $refund->refund_date = now()->toDateString(); $refund->reason = trim($reason); $refund->refunded_by_user_id = $actor->id; $refund->save();
            $this->payments->recalculate($customerReturn->salesOrder);
            $this->audit->record('payment.refunded', $actor, $customerReturn->organization, $customerReturn->store, $refund, newValues: [
                'refund_number' => $refund->refund_number,
                'refund_date' => $refund->refund_date->toDateString(),
                'order_number' => $customerReturn->salesOrder->order_number,
                'return_number' => $customerReturn->return_number,
                'credit_note_numbers' => $customerReturn->creditNotes->where('status', 'issued')->pluck('credit_note_number')->values()->all(),
                'payment_number' => $payment->payment_number,
                'method' => $payment->method->value,
                'financial_account_id' => $payment->financial_account_id,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
            ]);
            return $refund;
        }, 3);
    }
}
