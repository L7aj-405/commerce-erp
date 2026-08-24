<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;

class WarehousePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->active($organization->getKey()) && $user->hasPermission($organization, 'warehouses.view');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->active($warehouse->organization_id) && $user->hasPermission($warehouse->organization_id, 'warehouses.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->active($organization->getKey()) && $user->hasPermission($organization, 'warehouses.create');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->active($warehouse->organization_id) && $user->hasPermission($warehouse->organization_id, 'warehouses.update');
    }

    private function active(int $organizationId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId;
    }
}
