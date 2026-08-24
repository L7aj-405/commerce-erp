<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Support\InventoryTestCase;

class InventoryTenantAttackTest extends InventoryTestCase
{
    private User $userA;

    private User $userB;

    private Organization $organizationA;

    private Organization $organizationB;

    private Organization $organizationC;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Warehouse $warehouseC;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private ProductVariant $variantC;

    private int $reservationB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->organizationA = $this->createOrganization($this->userA, 'Attack Org A');
        $this->warehouseA = $this->createWarehouse($this->organizationA, 'Attack Warehouse A');
        $this->variantA = $this->createProduct($this->organizationA, 'Attack Product A', 'ATTACK-A')->variants->first();
        $this->openStock($this->userA, $this->organizationA, $this->warehouseA, $this->variantA);

        $this->organizationB = $this->createOrganization($this->userA, 'Secret Org B');
        $this->warehouseB = $this->createWarehouse($this->organizationB, 'Secret Warehouse B');
        $this->variantB = $this->createProduct($this->organizationB, 'Secret Product B', 'SECRET-B')->variants->first();
        $this->activate($this->userA, $this->organizationB);
        $this->openStock($this->userA, $this->organizationB, $this->warehouseB, $this->variantB);
        $this->reservationB = $this->reserve($this->userA, $this->organizationB, $this->warehouseB, $this->variantB)->id;

        $this->organizationC = $this->createOrganization($this->userB, 'Secret Org C');
        $this->warehouseC = $this->createWarehouse($this->organizationC, 'Secret Warehouse C');
        $this->variantC = $this->createProduct($this->organizationC, 'Secret Product C', 'SECRET-C')->variants->first();
        $this->activate($this->userB, $this->organizationC);
        $this->openStock($this->userB, $this->organizationC, $this->warehouseC, $this->variantC);
        $this->activate($this->userA, $this->organizationA);
    }

    public function test_exact_foreign_warehouse_id_cannot_resolve_outside_active_organization(): void
    {
        $this->actingAs($this->userA)->patch(route('inventory.warehouses.update', $this->warehouseB), [
            'name' => 'Compromised', 'code' => $this->warehouseB->code, 'status' => 'active',
        ])->assertNotFound();
    }

    public function test_foreign_warehouse_adjustment_is_rejected_and_does_not_mutate_foreign_stock(): void
    {
        $before = $this->balance($this->organizationB, $this->warehouseB, $this->variantB)->on_hand;
        $this->actingAs($this->userA)->postJson(route('inventory.adjustments.store'), [
            'warehouse_id' => $this->warehouseB->id, 'product_variant_id' => $this->variantB->id,
            'type' => 'adjustment_out', 'quantity' => '1.0000', 'reason' => 'Attack',
        ])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id', 'product_variant_id']);
        $this->assertSame($before, $this->balance($this->organizationB, $this->warehouseB, $this->variantB)->on_hand);
    }

    public function test_foreign_variant_cannot_be_reserved_with_local_warehouse(): void
    {
        $this->actingAs($this->userA)->postJson(route('inventory.reservations.store'), [
            'warehouse_id' => $this->warehouseA->id, 'product_variant_id' => $this->variantB->id, 'quantity' => '1.0000',
        ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');
    }

    public function test_forged_organization_actor_and_balance_fields_are_not_trusted(): void
    {
        $this->actingAs($this->userA)->post(route('inventory.adjustments.store'), [
            'organization_id' => $this->organizationB->id,
            'performed_by_user_id' => $this->userB->id,
            'on_hand' => '9999.0000', 'reserved' => '9999.0000',
            'warehouse_id' => $this->warehouseA->id, 'product_variant_id' => $this->variantA->id,
            'type' => 'adjustment_in', 'quantity' => '1.0000', 'reason' => 'Authorized local operation',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_movements', ['organization_id' => $this->organizationA->id, 'performed_by_user_id' => $this->userA->id, 'quantity' => 1]);
        $this->assertSame('11.0000', $this->balance($this->organizationA, $this->warehouseA, $this->variantA)->on_hand);
    }

    public function test_foreign_reservation_release_and_consume_routes_are_non_discoverable(): void
    {
        $this->actingAs($this->userA)->post(route('inventory.reservations.release', $this->reservationB))->assertNotFound();
        $this->actingAs($this->userA)->post(route('inventory.reservations.consume', $this->reservationB))->assertNotFound();
    }

    public function test_foreign_balance_filter_and_exact_warehouse_do_not_leak_names(): void
    {
        $response = $this->actingAs($this->userA)->getJson(route('inventory.stock.index', ['warehouse' => $this->warehouseB->id]))->assertUnprocessable();
        $this->assertStringNotContainsString('Secret Warehouse B', $response->getContent());
        $this->assertStringNotContainsString('Secret Product B', $response->getContent());
    }

    public function test_membership_in_two_organizations_does_not_bypass_active_context(): void
    {
        $this->actingAs($this->userA)->get(route('inventory.warehouses.index'))
            ->assertOk()->assertSee('Attack Warehouse A')->assertDontSee('Secret Warehouse B');

        $this->activate($this->userA, $this->organizationB);
        $this->actingAs($this->userA)->get(route('inventory.warehouses.index'))
            ->assertOk()->assertSee('Secret Warehouse B')->assertDontSee('Attack Warehouse A');
    }

    public function test_unrelated_user_cannot_use_exact_ids_from_another_tenant(): void
    {
        $this->activate($this->userB, $this->organizationC);
        $this->actingAs($this->userB)->patch(route('inventory.warehouses.update', $this->warehouseA), [
            'name' => 'Attack', 'code' => $this->warehouseA->code, 'status' => 'active',
        ])->assertNotFound();
        $this->actingAs($this->userB)->postJson(route('inventory.opening.store'), [
            'warehouse_id' => $this->warehouseA->id, 'product_variant_id' => $this->variantA->id, 'quantity' => '1.0000',
        ])->assertJsonValidationErrors(['warehouse_id', 'product_variant_id']);
    }
}
