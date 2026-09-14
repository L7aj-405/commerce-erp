<?php

namespace App\Actions\Payments;

use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ManageFinancialAccountAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FinancialAccount
    {
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $organization->status === 'active'
            && $actor->hasPermission($organization, 'financial_accounts.create'),
            403,
        );

        return DB::transaction(function () use ($actor, $organization, $data) {
            $account = new FinancialAccount;
            $account->organization_id = $organization->getKey();
            $this->apply($account, $data);
            $account->save();
            $this->audit->record('financial_account.created', $actor, $organization, auditable: $account, newValues: $account->only([
                'name', 'code', 'type', 'status', 'currency_code', 'accepted_methods',
            ]));

            return $account;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, FinancialAccount $account, array $data): FinancialAccount
    {
        abort_unless(
            $actor->active_organization_id === $account->organization_id
            && $account->organization->status === 'active'
            && $actor->hasPermission($account->organization_id, 'financial_accounts.update'),
            403,
        );

        return DB::transaction(function () use ($actor, $account, $data) {
            $old = $account->only(['name', 'code', 'type', 'status', 'currency_code', 'accepted_methods']);
            $this->apply($account, $data);
            $account->save();
            $this->audit->record('financial_account.updated', $actor, $account->organization, auditable: $account, oldValues: $old, newValues: $account->only([
                'name', 'code', 'type', 'status', 'currency_code', 'accepted_methods',
            ]));

            return $account;
        });
    }

    /** @param array<string, mixed> $data */
    private function apply(FinancialAccount $account, array $data): void
    {
        $account->name = $data['name'];
        $account->code = strtoupper($data['code']);
        $account->type = $data['type'];
        $account->status = $data['status'];
        $account->currency_code = strtoupper($data['currency_code']);
        $account->notes = $data['notes'] ?? null;
        $account->accepted_methods = array_values(array_unique($data['accepted_methods'] ?? []));
    }
}
