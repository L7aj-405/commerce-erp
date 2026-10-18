<?php

namespace App\Actions\Finance;

use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Enums\FinancePaymentInstrumentState;
use App\Models\FinanceObligation;
use App\Models\FinancePaymentInstrument;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceInstrumentCommitmentService;
use App\Services\Finance\FinanceNumberGenerator;
use App\Services\Finance\FinanceObligationCalculator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageFinancePaymentInstrumentAction
{
    public function __construct(private readonly FinanceNumberGenerator $numbers, private readonly FinanceObligationCalculator $calculator,
        private readonly FinanceInstrumentCommitmentService $commitments, private readonly RecordFinanceOutgoingPaymentAction $payments, private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, FinanceObligation $obligation, array $data): FinancePaymentInstrument
    {
        $this->authorizeManage($actor, $obligation->organization); $amount = Decimal::positive($data['amount']);
        return DB::transaction(function () use ($actor, $obligation, $data, $amount) {
            $obligation = FinanceObligation::query()->where('organization_id', $obligation->organization_id)->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            if ($obligation->cancelled_at) throw ValidationException::withMessages(['obligation' => 'Une obligation annulée ne peut pas recevoir un instrument.']);
            $account = FinancialAccount::query()->where('organization_id', $obligation->organization_id)->whereKey($data['financial_account_id'])->first();
            if (! $account || $account->status->value !== 'active' || $account->currency_code !== $obligation->currency_code) throw ValidationException::withMessages(['financial_account_id' => 'Le compte financier est invalide, inactif ou dans une autre devise.']);
            $issue = Carbon::parse($data['issue_date']); $maturity = Carbon::parse($data['maturity_date']);
            if ($maturity->lt($issue)) throw ValidationException::withMessages(['maturity_date' => 'L’échéance doit être postérieure ou égale à la date d’émission.']);
            $remaining = $this->calculator->values($obligation, $issue, true)['remaining']; $committed = $this->commitments->committed($obligation, null, true);
            if (Decimal::compare($amount, Decimal::subtract($remaining, $committed)) > 0) throw ValidationException::withMessages(['amount' => 'Le montant dépasse le reste non payé et non engagé de cette obligation.']);
            $instrument = new FinancePaymentInstrument; $instrument->organization_id = $obligation->organization_id; $instrument->store_id = $obligation->store_id;
            $instrument->instrument_number = $this->numbers->nextPaymentInstrument($obligation->organization, (int) $issue->format('Y'), $data['type']);
            $instrument->type = $data['type']; $instrument->external_reference = trim($data['external_reference']); $instrument->supplier_id = $obligation->supplier_id;
            $instrument->beneficiary_name = $obligation->supplier?->name ?: $obligation->beneficiary_name; $instrument->obligation_id = $obligation->id;
            $instrument->financial_account_id = $account->id; $instrument->amount = $amount; $instrument->currency_code = $obligation->currency_code;
            $instrument->issue_date = $issue; $instrument->maturity_date = $maturity; $instrument->state = FinancePaymentInstrumentState::Issued;
            $instrument->clearing_idempotency_key = (string) Str::uuid(); $instrument->notes = ($data['notes'] ?? null) ?: null; $instrument->created_by_user_id = $actor->id; $instrument->save();
            $this->audit->record('finance_payment_instrument.created', $actor, $obligation->organization, $obligation->store, $instrument, newValues: $this->values($instrument));
            return $instrument;
        }, 3);
    }

    public function present(User $actor, FinancePaymentInstrument $instrument): FinancePaymentInstrument
    {
        $this->authorizeManage($actor, $instrument->organization);
        return DB::transaction(function () use ($actor, $instrument) {
            $instrument = $this->locked($instrument); if ($instrument->state !== FinancePaymentInstrumentState::Issued) throw ValidationException::withMessages(['instrument' => 'Seul un instrument émis peut être présenté.']);
            $instrument->state = FinancePaymentInstrumentState::Presented; $instrument->presented_at = now(); $instrument->save();
            $this->audit->record('finance_payment_instrument.presented', $actor, $instrument->organization, $instrument->store, $instrument, newValues: $this->values($instrument)); return $instrument;
        }, 3);
    }

    public function cancel(User $actor, FinancePaymentInstrument $instrument, string $reason): FinancePaymentInstrument
    {
        $this->authorizeManage($actor, $instrument->organization); if (trim($reason) === '') throw ValidationException::withMessages(['reason' => 'Le motif est obligatoire.']);
        return DB::transaction(function () use ($actor, $instrument, $reason) {
            $instrument = $this->locked($instrument); if (! in_array($instrument->state, [FinancePaymentInstrumentState::Issued, FinancePaymentInstrumentState::Presented], true)) throw ValidationException::withMessages(['instrument' => 'Cet instrument ne peut plus être annulé.']);
            $instrument->state = FinancePaymentInstrumentState::Cancelled; $instrument->cancelled_at = now(); $instrument->cancelled_by_user_id = $actor->id; $instrument->cancellation_reason = trim($reason); $instrument->save();
            $this->audit->record('finance_payment_instrument.cancelled', $actor, $instrument->organization, $instrument->store, $instrument, newValues: $this->values($instrument) + ['reason' => $reason]); return $instrument;
        }, 3);
    }

    public function clear(User $actor, FinancePaymentInstrument $instrument): FinancePaymentInstrument
    {
        abort_unless($actor->active_organization_id === $instrument->organization_id && $actor->hasPermission($instrument->organization, 'finance.outgoing_payments.manage'), 403);
        if ($instrument->state === FinancePaymentInstrumentState::Cleared && $instrument->outgoing_payment_id) return $instrument;
        return DB::transaction(function () use ($actor, $instrument) {
            FinancialAccount::query()->where('organization_id', $instrument->organization_id)->whereKey($instrument->financial_account_id)->lockForUpdate()->firstOrFail();
            FinanceObligation::query()->where('organization_id', $instrument->organization_id)->whereKey($instrument->obligation_id)->lockForUpdate()->firstOrFail();
            $instrument = $this->locked($instrument);
            if ($instrument->state === FinancePaymentInstrumentState::Cleared && $instrument->outgoing_payment_id) return $instrument;
            if (! in_array($instrument->state, [FinancePaymentInstrumentState::Issued, FinancePaymentInstrumentState::Presented], true)) throw ValidationException::withMessages(['instrument' => 'Cet instrument ne peut pas être compensé.']);
            $payment = $this->payments->execute($actor, $instrument->organization, [
                'store_id' => $instrument->store_id, 'financial_account_id' => $instrument->financial_account_id, 'supplier_id' => $instrument->supplier_id,
                'beneficiary_name' => $instrument->beneficiary_name, 'payment_method' => $instrument->type->value, 'amount' => $instrument->amount,
                'currency_code' => $instrument->currency_code, 'payment_date' => now()->toDateString(), 'reference' => $instrument->external_reference,
                'notes' => $instrument->notes, 'idempotency_key' => $instrument->clearing_idempotency_key, 'instrument_id' => $instrument->id,
                'allocations' => [['obligation_id' => $instrument->obligation_id, 'amount' => $instrument->amount]],
            ]);
            $instrument->state = FinancePaymentInstrumentState::Cleared; $instrument->cleared_at = now(); $instrument->outgoing_payment_id = $payment->id; $instrument->save();
            $this->audit->record('finance_payment_instrument.cleared', $actor, $instrument->organization, $instrument->store, $instrument, newValues: $this->values($instrument) + ['outgoing_payment_id' => $payment->id]); return $instrument;
        }, 3);
    }

    private function authorizeManage(User $actor, Organization $organization): void
    { abort_unless($organization->status === 'active' && $actor->active_organization_id === $organization->id && $actor->hasPermission($organization, 'finance.monthly_situation.manage'), 403); }
    private function locked(FinancePaymentInstrument $instrument): FinancePaymentInstrument
    { return FinancePaymentInstrument::query()->where('organization_id', $instrument->organization_id)->whereKey($instrument->id)->lockForUpdate()->firstOrFail(); }
    private function values(FinancePaymentInstrument $instrument): array
    { return ['instrument_number' => $instrument->instrument_number, 'type' => $instrument->type->value, 'external_reference' => $instrument->external_reference, 'supplier_id' => $instrument->supplier_id, 'obligation_id' => $instrument->obligation_id, 'amount' => $instrument->amount, 'issue_date' => $instrument->issue_date->toDateString(), 'maturity_date' => $instrument->maturity_date->toDateString(), 'financial_account_id' => $instrument->financial_account_id, 'state' => $instrument->state->value]; }
}
