<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\ActiveTenantContext;

class SalesOrderProcurementPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'procurement.view');
    }

    public function view(User $user, SalesOrderProcurement $procurement): bool
    {
        return $this->allowed($user, $procurement->organization_id, 'procurement.view');
    }

    /** Create, record availability, change supplier, order, cancel. */
    public function manage(User $user, SalesOrderProcurement $procurement): bool
    {
        return $this->allowed($user, $procurement->organization_id, 'procurement.manage');
    }

    /** Confirm the physical supplier receipt. */
    public function receive(User $user, SalesOrderProcurement $procurement): bool
    {
        return $this->allowed($user, $procurement->organization_id, 'procurement.receive');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
