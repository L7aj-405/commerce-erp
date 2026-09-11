<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActiveTenantContext;

class SupplierPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->allowed($user, $supplier->organization_id, 'suppliers.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->allowed($user, $supplier->organization_id, 'suppliers.manage');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
