<?php

namespace App\Policies;

use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;

class InventoryMovementPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey());
    }

    public function view(User $user, InventoryMovement $movement): bool
    {
        return $this->allowed($user, $movement->organization_id);
    }

    private function allowed(User $user, int $organizationId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, 'inventory.view');
    }
}
