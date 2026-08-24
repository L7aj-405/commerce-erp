<?php

namespace App\Policies;

use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;

class InventoryReservationPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.view');
    }

    public function view(User $user, InventoryReservation $reservation): bool
    {
        return $this->allowed($user, $reservation->organization_id, 'inventory.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.reserve');
    }

    public function release(User $user, InventoryReservation $reservation): bool
    {
        return $this->allowed($user, $reservation->organization_id, 'inventory.release');
    }

    public function consume(User $user, InventoryReservation $reservation): bool
    {
        return $this->allowed($user, $reservation->organization_id, 'inventory.consume');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
