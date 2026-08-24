<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Tests\Support\InventoryTestCase;

class InventoryDatabaseIntegrityTest extends InventoryTestCase
{
    public function test_balance_identity_is_unique_per_organization_warehouse_variant(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);

        $duplicate = new InventoryBalance;
        $duplicate->organization_id = $organization->id;
        $duplicate->warehouse_id = $warehouse->id;
        $duplicate->product_variant_id = $variant->id;
        $duplicate->on_hand = '0.0000';
        $duplicate->reserved = '0.0000';
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_composite_foreign_key_rejects_cross_tenant_warehouse_variant_balance(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $warehouseA = $this->createWarehouse($organizationA);
        $variantB = $this->createProduct($organizationB)->variants->first();

        $balance = new InventoryBalance;
        $balance->organization_id = $organizationA->id;
        $balance->warehouse_id = $warehouseA->id;
        $balance->product_variant_id = $variantB->id;
        $balance->on_hand = '1.0000';
        $balance->reserved = '0.0000';
        $this->expectException(QueryException::class);
        $balance->save();
    }

    public function test_composite_foreign_keys_reject_cross_tenant_movement_and_reservation(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $warehouseA = $this->createWarehouse($organizationA);
        $variantB = $this->createProduct($organizationB)->variants->first();

        foreach ([new InventoryMovement, new InventoryReservation] as $model) {
            try {
                $model->organization_id = $organizationA->id;
                $model->warehouse_id = $warehouseA->id;
                $model->product_variant_id = $variantB->id;
                $model->quantity = '1.0000';
                if ($model instanceof InventoryMovement) {
                    $model->movement_type = 'opening';
                    $model->quantity_before = '0.0000';
                    $model->quantity_after = '1.0000';
                } else {
                    $model->status = 'active';
                }
                $model->save();
                $this->fail('Expected tenant-aware foreign key rejection.');
            } catch (QueryException) {
                $this->assertDatabaseCount($model->getTable(), 0);
            }
        }
    }

    public function test_authoritative_inventory_fields_are_guarded_against_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);
        InventoryBalance::query()->create(['organization_id' => 999, 'on_hand' => '999.0000', 'reserved' => '0.0000']);
    }
}
