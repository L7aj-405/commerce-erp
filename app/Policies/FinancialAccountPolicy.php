<?php

namespace App\Policies;

use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;

class FinancialAccountPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'financial_accounts.view');
    }

    public function view(User $user, FinancialAccount $account): bool
    {
        return $this->allowed($user, $account->organization_id, 'financial_accounts.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'financial_accounts.create');
    }

    public function update(User $user, FinancialAccount $account): bool
    {
        return $this->allowed($user, $account->organization_id, 'financial_accounts.update');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId && $user->hasPermission($organizationId, $permission);
    }
}
