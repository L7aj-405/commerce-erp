<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountOpeningPosition;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetFinancialAccountOpeningPositionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $data */
    public function execute(User $actor, FinancialAccount $account, array $data): FinancialAccountOpeningPosition
    {
        abort_unless($actor->active_organization_id === $account->organization_id
            && $actor->hasPermission($account->organization_id, 'finance.financial_accounts.manage_balance'), 403);

        return DB::transaction(function () use ($actor, $account, $data) {
            $locked = FinancialAccount::query()->withoutGlobalScopes()->where('organization_id', $account->organization_id)->lockForUpdate()->findOrFail($account->id);
            if (strtoupper($data['currency_code']) !== $locked->currency_code) {
                throw ValidationException::withMessages(['currency_code' => 'La devise doit correspondre à celle du compte.']);
            }
            $position = FinancialAccountOpeningPosition::query()->withoutGlobalScopes()
                ->where('organization_id', $locked->organization_id)->where('financial_account_id', $locked->id)->lockForUpdate()->first();
            $old = $position?->only(['effective_date', 'amount', 'currency_code', 'reference', 'notes']) ?? [];
            $position ??= new FinancialAccountOpeningPosition;
            $position->organization_id = $locked->organization_id;
            $position->financial_account_id = $locked->id;
            $position->effective_date = $data['effective_date'];
            $position->amount = $data['amount'];
            $position->currency_code = $locked->currency_code;
            $position->reference = $data['reference'] ?? null;
            $position->notes = $data['notes'] ?? null;
            $position->created_by_user_id ??= $actor->id;
            $position->save();
            $this->audit->record($old ? 'financial_account.opening_position_updated' : 'financial_account.opening_position_set', $actor,
                $locked->organization, auditable: $position, oldValues: $old, newValues: $position->only(['financial_account_id','effective_date','amount','currency_code','reference']));
            return $position;
        });
    }
}
