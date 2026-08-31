<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\ActiveTenantContext;

class StockTransferPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.view');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->allowed($user, $transfer->organization_id, 'inventory.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.transfer');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
