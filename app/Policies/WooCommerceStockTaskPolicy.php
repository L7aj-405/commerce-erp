<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Models\WooCommerceStockTask;
use App\Services\ActiveTenantContext;

class WooCommerceStockTaskPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'integrations.woocommerce.stock_tasks.view');
    }

    public function view(User $user, WooCommerceStockTask $task): bool
    {
        return $this->allowed($user, $task->organization_id, 'integrations.woocommerce.stock_tasks.view');
    }

    public function complete(User $user, WooCommerceStockTask $task): bool
    {
        return $this->allowed($user, $task->organization_id, 'integrations.woocommerce.stock_tasks.manage');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
