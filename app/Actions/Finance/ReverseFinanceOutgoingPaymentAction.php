<?php

namespace App\Actions\Finance;

use App\Enums\FinanceOutgoingPaymentStatus;
use App\Models\FinanceObligation;
use App\Models\FinanceOutgoingPayment;
use App\Models\FinancePaymentInstrument;
use App\Enums\FinancePaymentInstrumentState;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseFinanceOutgoingPaymentAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, FinanceOutgoingPayment $payment, string $reason): FinanceOutgoingPayment
    {
        abort_unless($actor->active_organization_id === $payment->organization_id && $actor->hasPermission($payment->organization_id, 'finance.outgoing_payments.manage'), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif d’inversion est obligatoire.']);
        }

        return DB::transaction(function () use ($actor, $payment, $reason) {
            $obligationIds = $payment->allocations()->orderBy('obligation_id')->pluck('obligation_id');
            FinanceObligation::query()->where('organization_id', $payment->organization_id)->whereIn('id', $obligationIds)->orderBy('id')->lockForUpdate()->get();
            $payment = FinanceOutgoingPayment::query()->where('organization_id', $payment->organization_id)->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($payment->status !== FinanceOutgoingPaymentStatus::Posted) {
                throw ValidationException::withMessages(['payment' => 'Seul un paiement comptabilisé peut être inversé.']);
            }

            $payment->status = FinanceOutgoingPaymentStatus::Reversed;
            $payment->reversed_at = now();
            $payment->reversed_by_user_id = $actor->getKey();
            $payment->reversal_reason = trim($reason);
            $payment->save();
            $instrument = FinancePaymentInstrument::query()->where('organization_id', $payment->organization_id)->where('outgoing_payment_id', $payment->id)->lockForUpdate()->first();
            if ($instrument) {
                $instrument->state = FinancePaymentInstrumentState::Returned;
                $instrument->returned_at = now();
                $instrument->save();
            }
            $this->audit->record('finance_outgoing_payment.reversed', $actor, $payment->organization, $payment->store, $payment, oldValues: ['status' => 'posted'], newValues: [
                'payment_number' => $payment->payment_number,
                'status' => 'reversed',
                'reason' => $payment->reversal_reason,
                'payment_instrument_id' => $instrument?->id,
            ]);

            return $payment;
        }, 3);
    }
}
