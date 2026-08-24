<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AdjustInventoryAction;
use App\Actions\Inventory\OpeningStockAction;
use App\Actions\Inventory\TransferInventoryAction;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InventoryTestCase;

class InventoryOperationTest extends InventoryTestCase
{
    public function test_opening_stock_creates_balance_and_immutable_movement_with_exact_before_after(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();

        $movement = app(OpeningStockAction::class)->execute($owner, $organization, $warehouse, $variant, '10.1250', 'Counted', 'OPEN-1');

        $balance = $this->balance($organization, $warehouse, $variant);
        $this->assertSame('10.1250', $balance->on_hand);
        $this->assertSame('0.0000', $balance->reserved);
        $this->assertSame('10.1250', $balance->available);
        $this->assertSame(InventoryMovementType::Opening, $movement->movement_type);
        $this->assertSame('0.0000', $movement->quantity_before);
        $this->assertSame('10.1250', $movement->quantity_after);
        $this->assertSame($owner->id, $movement->performed_by_user_id);
    }

    public function test_duplicate_opening_stock_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);

        $this->expectException(ValidationException::class);
        app(OpeningStockAction::class)->execute($owner, $organization, $warehouse, $variant, '1.0000');
    }

    public function test_adjustment_in_and_out_update_balance_and_ledger(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        $action = app(AdjustInventoryAction::class);

        $action->execute($owner, $organization, $warehouse, $variant, InventoryMovementType::AdjustmentIn, '2.5000', 'Received recount');
        $out = $action->execute($owner, $organization, $warehouse, $variant, InventoryMovementType::AdjustmentOut, '1.2500', 'Damaged');

        $this->assertSame('11.2500', $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertSame('-1.2500', $out->quantity);
        $this->assertSame('12.5000', $out->quantity_before);
        $this->assertSame('11.2500', $out->quantity_after);
    }

    public function test_adjustment_out_cannot_reduce_available_stock_below_zero(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '2.0000');

        try {
            app(AdjustInventoryAction::class)->execute($owner, $organization, $warehouse, $variant, InventoryMovementType::AdjustmentOut, '2.0001', 'Too much');
            $this->fail('Expected the negative-stock guard to reject the adjustment.');
        } catch (ValidationException) {
            $this->assertSame('2.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
        }
    }

    public function test_adjustment_reason_is_required_before_mutation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();

        $this->actingAs($owner)->postJson(route('inventory.adjustments.store'), [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'type' => 'adjustment_in', 'quantity' => '1.0000',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_foreign_warehouse_and_variant_are_rejected_by_scoped_validation(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $warehouseA = $this->createWarehouse($organizationA, 'A');
        $warehouseB = $this->createWarehouse($organizationB, 'B');
        $variantA = $this->createProduct($organizationA, 'A')->variants->first();
        $variantB = $this->createProduct($organizationB, 'B')->variants->first();

        $this->actingAs($ownerA)->postJson(route('inventory.opening.store'), ['warehouse_id' => $warehouseB->id, 'product_variant_id' => $variantA->id, 'quantity' => '1.0000'])->assertJsonValidationErrors('warehouse_id');
        $this->actingAs($ownerA)->postJson(route('inventory.opening.store'), ['warehouse_id' => $warehouseA->id, 'product_variant_id' => $variantB->id, 'quantity' => '1.0000'])->assertJsonValidationErrors('product_variant_id');
    }

    public function test_unauthorized_opening_stock_is_forbidden_before_domain_logic(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['inventory.view']);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->post(route('inventory.opening.store'), ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => '1.0000'])
            ->assertForbidden();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_domain_action_rejects_an_organization_that_is_not_the_actor_active_context(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'A');
        $organizationB = $this->createOrganization($owner, 'B');
        $warehouseB = $this->createWarehouse($organizationB);
        $variantB = $this->createProduct($organizationB)->variants->first();
        $this->activate($owner, $organizationA);

        $this->expectException(HttpException::class);
        app(OpeningStockAction::class)->execute($owner, $organizationB, $warehouseB, $variantB, '1.0000');
    }

    public function test_balance_and_movement_roll_back_if_audit_fails(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        $this->mock(AuditLogger::class, function (MockInterface $mock) {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        });

        try {
            app(AdjustInventoryAction::class)->execute($owner, $organization, $warehouse, $variant, InventoryMovementType::AdjustmentIn, '5.0000', 'Rollback');
            $this->fail('Expected audit failure.');
        } catch (RuntimeException) {
            $this->assertSame('10.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
            $this->assertDatabaseCount('inventory_movements', 1);
        }
    }

    public function test_inventory_movements_cannot_be_updated_or_deleted(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);
        $movement = InventoryMovement::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $movement->reason = 'Tampered';
        $movement->save();
    }

    public function test_inventory_movements_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);

        $this->expectException(LogicException::class);
        InventoryMovement::query()->firstOrFail()->delete();
    }

    public function test_opening_and_adjustment_are_audited_without_forged_actor_or_tenant(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);
        app(AdjustInventoryAction::class)->execute($owner, $organization, $warehouse, $variant, InventoryMovementType::AdjustmentIn, '1.0000', 'Count');

        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'actor_id' => $owner->id, 'event' => 'inventory.opening_stock']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'actor_id' => $owner->id, 'event' => 'inventory.adjusted']);
    }

    public function test_transfer_moves_stock_between_warehouses_with_two_linked_movements(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Source');
        $destination = $this->createWarehouse($organization, 'Destination');
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $source, $variant, '10.0000');

        $movements = app(TransferInventoryAction::class)->execute($owner, $organization, $source, $destination, $variant, '4.0000', 'Replenishment', 'MOVE-1');

        $this->assertSame('6.0000', $this->balance($organization, $source, $variant)->on_hand);
        $this->assertSame('4.0000', $this->balance($organization, $destination, $variant)->on_hand);
        $this->assertSame(InventoryMovementType::TransferOut, $movements['out']->movement_type);
        $this->assertSame(InventoryMovementType::TransferIn, $movements['in']->movement_type);
        $this->assertSame($movements['out']->reference, $movements['in']->reference);
    }

    public function test_transfer_rejects_insufficient_available_stock_atomically(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $source = $this->createWarehouse($organization, 'Source');
        $destination = $this->createWarehouse($organization, 'Destination');
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $source, $variant, '2.0000');

        try {
            app(TransferInventoryAction::class)->execute($owner, $organization, $source, $destination, $variant, '3.0000', 'Too much');
            $this->fail('Expected transfer to reject insufficient stock.');
        } catch (ValidationException) {
            $this->assertSame('2.0000', $this->balance($organization, $source, $variant)->on_hand);
            $this->assertDatabaseMissing('inventory_balances', ['warehouse_id' => $destination->id, 'on_hand' => 3]);
            $this->assertDatabaseCount('inventory_movements', 1);
        }
    }
}
