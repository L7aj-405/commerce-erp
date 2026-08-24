<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Tests\Support\InventoryTestCase;

class WarehouseTest extends InventoryTestCase
{
    public function test_authorized_user_can_create_a_warehouse_in_the_active_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->post(route('inventory.warehouses.store'), [
            'name' => 'Central', 'code' => 'CENTRAL', 'description' => 'Primary stock', 'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('warehouses', ['organization_id' => $organization->id, 'name' => 'Central', 'code' => 'CENTRAL']);
    }

    public function test_authorized_user_can_update_a_warehouse_without_deleting_history(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);

        $this->actingAs($owner)->patch(route('inventory.warehouses.update', $warehouse), [
            'name' => 'Central Archive', 'code' => $warehouse->code, 'description' => 'Retained', 'status' => 'inactive',
        ])->assertRedirect();

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'organization_id' => $organization->id, 'status' => 'inactive']);
    }

    public function test_warehouse_creation_ignores_a_forged_organization_id(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');

        $this->actingAs($ownerA)->post(route('inventory.warehouses.store'), [
            'organization_id' => $organizationB->id, 'name' => 'Safe', 'code' => 'SAFE', 'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('warehouses', ['organization_id' => $organizationA->id, 'code' => 'SAFE']);
        $this->assertDatabaseMissing('warehouses', ['organization_id' => $organizationB->id, 'code' => 'SAFE']);
    }

    public function test_route_binding_blocks_a_warehouse_from_another_active_organization(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'A');
        $organizationB = $this->createOrganization($owner, 'B');
        $warehouseB = $this->createWarehouse($organizationB, 'B warehouse');
        $this->activate($owner, $organizationA);

        $this->actingAs($owner)->patch(route('inventory.warehouses.update', $warehouseB), [
            'name' => 'Attack', 'code' => $warehouseB->code, 'status' => 'active',
        ])->assertNotFound();
    }

    public function test_user_without_warehouse_permission_is_forbidden_before_creation(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $user, []);
        $this->activate($user, $organization);

        $this->actingAs($user)->post(route('inventory.warehouses.store'), ['name' => 'Denied', 'code' => 'DENIED'])
            ->assertForbidden();
        $this->assertDatabaseMissing('warehouses', ['organization_id' => $organization->id, 'code' => 'DENIED']);
    }
}
