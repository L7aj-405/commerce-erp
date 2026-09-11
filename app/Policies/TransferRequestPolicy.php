<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\ActiveTenantContext;

class TransferRequestPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'inventory.transfer_requests.view');
    }

    public function view(User $user, TransferRequest $request): bool
    {
        return $this->allowed($user, $request->organization_id, 'inventory.transfer_requests.view');
    }

    /** Préparer / Expédier / Annuler + configure replenishment. */
    public function manage(User $user, TransferRequest $request): bool
    {
        return $this->allowed($user, $request->organization_id, 'inventory.transfer_requests.manage');
    }

    /** Réceptionner (destination / Showroom user). */
    public function receive(User $user, TransferRequest $request): bool
    {
        return $this->allowed($user, $request->organization_id, 'inventory.transfer_requests.receive');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
