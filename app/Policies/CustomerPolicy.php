<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;

class CustomerPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->allowed($user, $customer->organization_id, 'customers.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->allowed($user, $customer->organization_id, 'customers.update');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId && $user->hasPermission($organizationId, $permission);
    }
}
