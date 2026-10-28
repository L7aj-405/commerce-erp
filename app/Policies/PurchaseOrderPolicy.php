<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ActiveTenantContext;

/** Supplier purchase orders reuse the procurement permissions (procurement.view / procurement.manage). */
class PurchaseOrderPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'procurement.view');
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->allowed($user, $purchaseOrder->organization_id, 'procurement.view');
    }

    /** Create from requirements, cancel. */
    public function manage(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->allowed($user, $purchaseOrder->organization_id, 'procurement.manage');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
