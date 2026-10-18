<?php

namespace App\Actions\Finance;

use App\Enums\FinanceOutgoingPaymentStatus;
use App\Models\FinanceObligation;
use App\Models\FinanceOutgoingPayment;
use App\Models\FinanceOutgoingPaymentAllocation;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceNumberGenerator;
use App\Services\Finance\FinanceInstrumentCommitmentService;
use App\Services\Finance\FinanceObligationCalculator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordFinanceOutgoingPaymentAction
{
    public function __construct(
        private readonly FinanceNumberGenerator $numbers,
        private readonly FinanceObligationCalculator $calculator,
        private readonly FinanceInstrumentCommitmentService $commitments,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data): FinanceOutgoingPayment
    {
        $this->authorize($actor, $organization);
        $amount = Decimal::positive($data['amount']);

        try {
            return DB::transaction(function () use ($actor, $organization, $data, $amount) {
            $existing = FinanceOutgoingPayment::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $data['idempotency_key'])
                ->with('allocations')
                ->first();
            if ($existing) {
                $this->assertIdempotentMatch($existing, $data, $amount);

                return $existing;
            }

            $account = FinancialAccount::query()->where('organization_id', $organization->getKey())->whereKey($data['financial_account_id'])->lockForUpdate()->first();
            if (! $account || $account->status->value !== 'active') {
                throw ValidationException::withMessages(['financial_account_id' => 'Le compte financier sélectionné est invalide ou inactif.']);
            }
            if ($account->currency_code !== strtoupper($data['currency_code'])) {
                throw ValidationException::withMessages(['currency_code' => 'La devise doit correspondre à celle du compte financier.']);
            }
            $this->assertOptionalReference(Store::class, $organization, $data['store_id'] ?? null, 'store_id');
            $this->assertOptionalReference(Supplier::class, $organization, $data['supplier_id'] ?? null, 'supplier_id');

            $allocations = collect($data['allocations'])->map(fn (array $allocation) => [
                'obligation_id' => (int) $allocation['obligation_id'],
                'amount' => Decimal::positive($allocation['amount'], 'allocations'),
            ])->sortBy('obligation_id')->values();
            if ($allocations->pluck('obligation_id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['allocations' => 'Une obligation ne peut apparaître qu’une fois dans un paiement.']);
            }
            $allocated = $allocations->reduce(fn (string $sum, array $allocation) => Decimal::add($sum, $allocation['amount']), '0.0000');
            if (Decimal::compare($allocated, $amount) !== 0) {
                throw ValidationException::withMessages(['allocations' => 'La somme des allocations doit correspondre exactement au montant du paiement.']);
            }

            $obligations = FinanceObligation::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('id', $allocations->pluck('obligation_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($obligations->count() !== $allocations->count()) {
                throw ValidationException::withMessages(['allocations' => 'Une obligation sélectionnée est invalide pour cette organisation.']);
            }

            foreach ($allocations as $allocation) {
                /** @var FinanceObligation $obligation */
                $obligation = $obligations->get($allocation['obligation_id']);
                if ($obligation->cancelled_at) {
                    throw ValidationException::withMessages(['allocations' => "L’obligation {$obligation->obligation_number} est annulée."]);
                }
                if ($obligation->currency_code !== strtoupper($data['currency_code'])) {
                    throw ValidationException::withMessages(['allocations' => "La devise de l’obligation {$obligation->obligation_number} ne correspond pas."]);
                }
                $values = $this->calculator->values($obligation, Carbon::parse($data['payment_date']), true);
                $committed = $this->commitments->committed($obligation, isset($data['instrument_id']) ? (int) $data['instrument_id'] : null, true);
                $available = Decimal::subtract($values['remaining'], $committed);
                if (Decimal::compare($allocation['amount'], $available) > 0) {
                    throw ValidationException::withMessages(['allocations' => "Le paiement dépasse le reste non engagé de l’obligation {$obligation->obligation_number}. Annulez d’abord le chèque ou la traite correspondant(e)."]);
                }
            }

            $paymentDate = Carbon::parse($data['payment_date']);
            $payment = new FinanceOutgoingPayment;
            $payment->organization_id = $organization->getKey();
            $payment->store_id = $data['store_id'] ?? null;
            $payment->payment_number = $this->numbers->nextOutgoingPayment($organization, (int) $paymentDate->format('Y'));
            $payment->financial_account_id = $account->getKey();
            $payment->supplier_id = $data['supplier_id'] ?? null;
            $payment->beneficiary_name = ($data['beneficiary_name'] ?? null) ?: null;
            $payment->payment_method = $data['payment_method'];
            $payment->amount = $amount;
            $payment->currency_code = strtoupper($data['currency_code']);
            $payment->payment_date = $paymentDate->toDateString();
            $payment->reference = ($data['reference'] ?? null) ?: null;
            $payment->notes = ($data['notes'] ?? null) ?: null;
            $payment->status = FinanceOutgoingPaymentStatus::Posted;
            $payment->idempotency_key = $data['idempotency_key'];
            $payment->recorded_by_user_id = $actor->getKey();
            $payment->save();

            foreach ($allocations as $allocation) {
                $row = new FinanceOutgoingPaymentAllocation;
                $row->organization_id = $organization->getKey();
                $row->outgoing_payment_id = $payment->getKey();
                $row->obligation_id = $allocation['obligation_id'];
                $row->amount = $allocation['amount'];
                $row->save();
                $this->audit->record('finance_outgoing_payment.allocated', $actor, $organization, $payment->store, $row, newValues: [
                    'payment_number' => $payment->payment_number,
                    'obligation_number' => $obligations->get($allocation['obligation_id'])->obligation_number,
                    'amount' => $allocation['amount'],
                ]);
            }

            $this->audit->record('finance_outgoing_payment.posted', $actor, $organization, $payment->store, $payment, newValues: [
                'payment_number' => $payment->payment_number,
                'amount' => $payment->amount,
                'payment_date' => $paymentDate->toDateString(),
                'financial_account_id' => $account->getKey(),
                'supplier_id' => $payment->supplier_id,
                'beneficiary_name' => $payment->beneficiary_name,
                'allocation_count' => $allocations->count(),
            ]);

                return $payment->load('allocations');
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = FinanceOutgoingPayment::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $data['idempotency_key'])
                ->with('allocations')
                ->first();
            if (! $existing) {
                throw $exception;
            }
            $this->assertIdempotentMatch($existing, $data, $amount);

            return $existing;
        }
    }

    private function authorize(User $actor, Organization $organization): void
    {
        abort_unless($organization->status === 'active' && $actor->active_organization_id === $organization->getKey() && $actor->hasPermission($organization, 'finance.outgoing_payments.manage'), 403);
    }

    private function assertOptionalReference(string $model, Organization $organization, mixed $id, string $field): void
    {
        if ($id !== null && ! $model::query()->where('organization_id', $organization->getKey())->whereKey($id)->exists()) {
            throw ValidationException::withMessages([$field => 'La référence sélectionnée n’appartient pas à cette organisation.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertIdempotentMatch(FinanceOutgoingPayment $payment, array $data, string $amount): void
    {
        $requested = collect($data['allocations'])->mapWithKeys(fn (array $row) => [(int) $row['obligation_id'] => Decimal::normalize($row['amount'])])->sortKeys()->all();
        $stored = $payment->allocations->mapWithKeys(fn ($row) => [$row->obligation_id => Decimal::normalize($row->amount)])->sortKeys()->all();
        if ($payment->financial_account_id !== (int) $data['financial_account_id']
            || $payment->store_id !== (($data['store_id'] ?? null) !== null ? (int) $data['store_id'] : null)
            || $payment->supplier_id !== (($data['supplier_id'] ?? null) !== null ? (int) $data['supplier_id'] : null)
            || $payment->beneficiary_name !== (($data['beneficiary_name'] ?? null) ?: null)
            || $payment->payment_method->value !== $data['payment_method']
            || $payment->currency_code !== strtoupper($data['currency_code'])
            || Decimal::compare($payment->amount, $amount) !== 0
            || $payment->payment_date->toDateString() !== Carbon::parse($data['payment_date'])->toDateString()
            || $requested !== $stored) {
            throw ValidationException::withMessages(['idempotency_key' => 'Cet identifiant d’opération a déjà été utilisé avec un autre paiement.']);
        }
    }
}
