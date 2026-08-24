<?php

namespace Tests\Support;

use App\Actions\Inventory\CreateReservationAction;
use App\Actions\Inventory\OpeningStockAction;
use App\Models\InventoryBalance;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

abstract class InventoryTestCase extends CatalogTestCase
{
    protected function createWarehouse(Organization $organization, string $name = 'Main Warehouse', ?string $code = null): Warehouse
    {
        $warehouse = new Warehouse;
        $warehouse->organization_id = $organization->getKey();
        $warehouse->name = $name;
        $warehouse->code = $code ?? 'WH-'.Str::upper(Str::random(8));
        $warehouse->description = null;
        $warehouse->status = 'active';
        $warehouse->save();

        return $warehouse;
    }

    protected function openStock(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, string $quantity = '10.0000'): void
    {
        app(OpeningStockAction::class)->execute($actor, $organization, $warehouse, $variant, $quantity, 'Test opening');
    }

    protected function balance(Organization $organization, Warehouse $warehouse, ProductVariant $variant): InventoryBalance
    {
        return InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->firstOrFail();
    }

    protected function reserve(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, string $quantity = '2.0000'): InventoryReservation
    {
        return app(CreateReservationAction::class)->execute($actor, $organization, $warehouse, $variant, $quantity);
    }
}
