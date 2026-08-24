<?php

namespace App\Policies;

use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;

class InventoryBalancePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.view');
    }

    public function view(User $user, InventoryBalance $balance): bool
    {
        return $this->allowed($user, $balance->organization_id, 'inventory.view');
    }

    public function opening(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.opening');
    }

    public function adjust(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.adjust');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
