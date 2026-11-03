<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Support\LockedRow;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryBalanceLocker
{
    public function lock(Organization $organization, Warehouse $warehouse, ProductVariant $variant): InventoryBalance
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Inventory balances may only be locked inside a database transaction.');
        }

        abort_unless(
            $warehouse->organization_id === $organization->getKey()
            && $variant->organization_id === $organization->getKey(),
            404
        );

        $identity = [
            'organization_id' => $organization->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'product_variant_id' => $variant->getKey(),
        ];

        // Existing row (the usual case): lock it directly — an exclusive record
        // lock, no gap lock. Missing row: create-and-lock exclusively in one
        // step. INSERT IGNORE + FOR UPDATE deadlocked on MySQL (see LockedRow).
        if (! InventoryBalance::query()->where($identity)->exists()) {
            LockedRow::claim('inventory_balances', $identity, [
                'on_hand' => '0.0000',
                'reserved' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return InventoryBalance::query()->where($identity)->lockForUpdate()->firstOrFail();
    }
}
