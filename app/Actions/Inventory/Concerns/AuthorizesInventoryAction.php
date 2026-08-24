<?php

namespace App\Actions\Inventory\Concerns;

use App\Enums\CatalogStatus;
use App\Enums\WarehouseStatus;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

trait AuthorizesInventoryAction
{
    private function authorizeInventory(User $actor, Organization $organization, string $permission): void
    {
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $organization->status === 'active'
            && $actor->hasPermission($organization, $permission),
            403
        );
    }

    private function validateInventoryIdentity(Organization $organization, Warehouse $warehouse, ProductVariant $variant): void
    {
        abort_unless(
            $warehouse->organization_id === $organization->getKey()
            && $variant->organization_id === $organization->getKey(),
            404
        );

        if ($warehouse->status !== WarehouseStatus::Active || $variant->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['warehouse_id' => 'Inventory operations require an active warehouse and variant.']);
        }
    }
}
