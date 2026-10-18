<?php

namespace App\Actions\Finance;

use App\Models\FinanceObligation;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceNumberGenerator;
use App\Services\Finance\FinanceObligationCalculator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageFinanceObligationAction
{
    public function __construct(
        private readonly FinanceNumberGenerator $numbers,
        private readonly FinanceObligationCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FinanceObligation
    {
        $this->authorize($actor, $organization);

        return DB::transaction(function () use ($actor, $organization, $data) {
            $this->assertReferences($organization, $data);
            $dueDate = Carbon::parse($data['due_date']);
            $obligation = new FinanceObligation;
            $obligation->organization_id = $organization->getKey();
            $obligation->obligation_number = $this->numbers->nextObligation($organization, (int) $dueDate->format('Y'));
            $obligation->created_by_user_id = $actor->getKey();
            $this->apply($obligation, $data, Decimal::positive($data['planned_amount'], 'planned_amount'));
            $obligation->save();

            $this->audit->record('finance_obligation.created', $actor, $organization, $obligation->store, $obligation, newValues: $this->auditValues($obligation));

            return $obligation;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, FinanceObligation $obligation, array $data): FinanceObligation
    {
        $this->authorize($actor, $obligation->organization);

        return DB::transaction(function () use ($actor, $obligation, $data) {
            $obligation = FinanceObligation::query()->where('organization_id', $obligation->organization_id)->whereKey($obligation->getKey())->lockForUpdate()->firstOrFail();
            if ($obligation->cancelled_at) {
                throw ValidationException::withMessages(['obligation' => 'Une obligation annulée ne peut plus être modifiée.']);
            }

            $this->assertReferences($obligation->organization, $data);
            $amount = Decimal::positive($data['planned_amount'], 'planned_amount');
            $paid = $this->calculator->paid($obligation, true);
            if (Decimal::compare($amount, $paid) < 0) {
                throw ValidationException::withMessages(['planned_amount' => 'Le montant prévu ne peut pas être inférieur au montant déjà payé.']);
            }
            if (Decimal::compare($paid, '0.0000') > 0) {
                $this->assertPaidStructureUnchanged($obligation, $data);
            }

            $old = $this->auditValues($obligation);
            $this->apply($obligation, $data, $amount);
            $obligation->save();
            $this->audit->record('finance_obligation.updated', $actor, $obligation->organization, $obligation->store, $obligation, $old, $this->auditValues($obligation));

            return $obligation;
        }, 3);
    }

    public function cancel(User $actor, FinanceObligation $obligation, string $reason): FinanceObligation
    {
        $this->authorize($actor, $obligation->organization);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif d’annulation est obligatoire.']);
        }

        return DB::transaction(function () use ($actor, $obligation, $reason) {
            $obligation = FinanceObligation::query()->where('organization_id', $obligation->organization_id)->whereKey($obligation->getKey())->lockForUpdate()->firstOrFail();
            if ($obligation->cancelled_at) {
                throw ValidationException::withMessages(['obligation' => 'Cette obligation est déjà annulée.']);
            }
            if (Decimal::compare($this->calculator->paid($obligation, true), '0.0000') > 0) {
                throw ValidationException::withMessages(['obligation' => 'Inversez d’abord les paiements comptabilisés avant d’annuler cette obligation.']);
            }

            $obligation->cancelled_at = now();
            $obligation->cancelled_by_user_id = $actor->getKey();
            $obligation->cancellation_reason = trim($reason);
            $obligation->save();
            $this->audit->record('finance_obligation.cancelled', $actor, $obligation->organization, $obligation->store, $obligation, newValues: [
                'obligation_number' => $obligation->obligation_number,
                'reason' => $obligation->cancellation_reason,
            ]);

            return $obligation;
        }, 3);
    }

    private function authorize(User $actor, Organization $organization): void
    {
        abort_unless($organization->status === 'active' && $actor->active_organization_id === $organization->getKey() && $actor->hasPermission($organization, 'finance.monthly_situation.manage'), 403);
    }

    /** @param array<string, mixed> $data */
    private function assertReferences(Organization $organization, array $data): void
    {
        foreach ([
            ['key' => 'store_id', 'model' => Store::class],
            ['key' => 'supplier_id', 'model' => Supplier::class],
            ['key' => 'preferred_financial_account_id', 'model' => FinancialAccount::class],
        ] as $reference) {
            $id = $data[$reference['key']] ?? null;
            if ($id !== null && ! $reference['model']::query()->where('organization_id', $organization->getKey())->whereKey($id)->exists()) {
                throw ValidationException::withMessages([$reference['key'] => 'La référence sélectionnée n’appartient pas à cette organisation.']);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function apply(FinanceObligation $obligation, array $data, string $amount): void
    {
        $obligation->store_id = $data['store_id'] ?? null;
        $obligation->category = $data['category'];
        $obligation->label = trim($data['label']);
        $obligation->supplier_id = $data['supplier_id'] ?? null;
        $obligation->beneficiary_name = ($data['beneficiary_name'] ?? null) ?: null;
        $obligation->reference = ($data['reference'] ?? null) ?: null;
        $obligation->due_date = $data['due_date'];
        $obligation->planned_amount = $amount;
        $obligation->currency_code = strtoupper($data['currency_code']);
        $obligation->planned_payment_method = $data['planned_payment_method'] ?? null;
        $obligation->preferred_financial_account_id = $data['preferred_financial_account_id'] ?? null;
        $obligation->notes = ($data['notes'] ?? null) ?: null;
    }

    /** @param array<string, mixed> $data */
    private function assertPaidStructureUnchanged(FinanceObligation $obligation, array $data): void
    {
        $existing = [
            'store_id' => $obligation->store_id,
            'category' => $obligation->category->value,
            'supplier_id' => $obligation->supplier_id,
            'beneficiary_name' => $obligation->beneficiary_name,
            'currency_code' => $obligation->currency_code,
            'planned_payment_method' => $obligation->planned_payment_method?->value,
            'preferred_financial_account_id' => $obligation->preferred_financial_account_id,
        ];
        $requested = [
            'store_id' => isset($data['store_id']) ? (int) $data['store_id'] : null,
            'category' => $data['category'],
            'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            'beneficiary_name' => ($data['beneficiary_name'] ?? null) ?: null,
            'currency_code' => strtoupper($data['currency_code']),
            'planned_payment_method' => ($data['planned_payment_method'] ?? null) ?: null,
            'preferred_financial_account_id' => isset($data['preferred_financial_account_id']) ? (int) $data['preferred_financial_account_id'] : null,
        ];
        if ($existing !== $requested) {
            throw ValidationException::withMessages([
                'obligation' => 'Après un paiement, la boutique, le type, le bénéficiaire, la devise et le compte prévu ne peuvent plus être modifiés.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function auditValues(FinanceObligation $obligation): array
    {
        return $obligation->only(['obligation_number', 'category', 'label', 'store_id', 'supplier_id', 'beneficiary_name', 'reference', 'due_date', 'planned_amount', 'currency_code', 'planned_payment_method', 'preferred_financial_account_id']);
    }
}
