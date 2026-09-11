<?php

namespace App\Policies;

use App\Models\NonStockItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;

class NonStockItemPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->scope($organization->getKey()) && $user->hasPermission($organization, 'quotations.view');
    }

    public function view(User $user, NonStockItem $item): bool
    {
        return $this->scope($item->organization_id) && $user->hasPermission($item->organization_id, 'quotations.view');
    }

    public function manage(User $user, Organization $organization): bool
    {
        return $this->scope($organization->getKey()) && $user->hasPermission($organization, 'non_stock_items.manage');
    }

    public function update(User $user, NonStockItem $item): bool
    {
        return $this->scope($item->organization_id) && $user->hasPermission($item->organization_id, 'non_stock_items.manage');
    }

    private function scope(int $organizationId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId;
    }
}
