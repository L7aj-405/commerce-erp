<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\ConsumeReservationAction;
use App\Actions\Inventory\CreateReservationAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Enums\InventoryReservationStatus;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
use Tests\Support\InventoryTestCase;

class InventoryReservationTest extends InventoryTestCase
{
    public function test_reservation_increases_reserved_and_reduces_derived_available(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('10.0000');
        $reservation = $this->reserve($owner, $organization, $warehouse, $variant, '3.0000');
        $balance = $this->balance($organization, $warehouse, $variant);

        $this->assertSame(InventoryReservationStatus::Active, $reservation->status);
        $this->assertSame('10.0000', $balance->on_hand);
        $this->assertSame('3.0000', $balance->reserved);
        $this->assertSame('7.0000', $balance->available);
    }

    public function test_over_reservation_is_rejected_without_changing_balance(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('5.0000');

        try {
            $this->reserve($owner, $organization, $warehouse, $variant, '5.0001');
            $this->fail('Expected over-reservation rejection.');
        } catch (ValidationException) {
            $this->assertSame('0.0000', $this->balance($organization, $warehouse, $variant)->reserved);
        }
    }

    public function test_sequential_contention_rechecks_locked_available_stock_and_prevents_overselling(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('10.0000');
        $this->reserve($owner, $organization, $warehouse, $variant, '6.0000');

        try {
            $this->reserve($owner, $organization, $warehouse, $variant, '5.0000');
            $this->fail('Expected the second reservation to observe the locked projection.');
        } catch (ValidationException) {
            $this->assertSame('6.0000', $this->balance($organization, $warehouse, $variant)->reserved);
            $this->assertDatabaseCount('inventory_reservations', 1);
        }
    }

    public function test_release_restores_available_stock_and_cannot_be_applied_twice(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('10.0000');
        $reservation = $this->reserve($owner, $organization, $warehouse, $variant, '3.0000');
        $action = app(ReleaseReservationAction::class);
        $released = $action->execute($owner, $organization, $reservation);

        $this->assertSame(InventoryReservationStatus::Released, $released->status);
        $this->assertSame('10.0000', $this->balance($organization, $warehouse, $variant)->available);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.reservation.released', 'organization_id' => $organization->id]);
        $this->expectException(ValidationException::class);
        $action->execute($owner, $organization, $released);
    }

    public function test_consume_reduces_on_hand_and_reserved_and_creates_physical_movement(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('10.0000');
        $reservation = $this->reserve($owner, $organization, $warehouse, $variant, '3.0000');
        $consumed = app(ConsumeReservationAction::class)->execute($owner, $organization, $reservation);
        $balance = $this->balance($organization, $warehouse, $variant);

        $this->assertSame(InventoryReservationStatus::Consumed, $consumed->status);
        $this->assertSame('7.0000', $balance->on_hand);
        $this->assertSame('0.0000', $balance->reserved);
        $this->assertSame('7.0000', $balance->available);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $reservation->id, 'movement_type' => 'reservation_consumed', 'quantity' => -3]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.reservation.consumed', 'organization_id' => $organization->id]);
    }

    public function test_consumed_reservation_cannot_be_consumed_or_released_again(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('10.0000');
        $reservation = $this->reserve($owner, $organization, $warehouse, $variant);
        $consumed = app(ConsumeReservationAction::class)->execute($owner, $organization, $reservation);

        foreach ([ConsumeReservationAction::class, ReleaseReservationAction::class] as $actionClass) {
            try {
                app($actionClass)->execute($owner, $organization, $consumed);
                $this->fail('Expected finalized reservation transition to fail.');
            } catch (ValidationException) {
                $this->assertSame('8.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
            }
        }
    }

    public function test_reference_pair_makes_reservation_creation_idempotent(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('10.0000');
        $action = app(CreateReservationAction::class);
        $first = $action->execute($owner, $organization, $warehouse, $variant, '2.0000', 'order', 501);
        $retry = $action->execute($owner, $organization, $warehouse, $variant, '2.0000', 'order', 501);

        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame('2.0000', $this->balance($organization, $warehouse, $variant)->reserved);
    }

    public function test_same_reference_with_different_quantity_is_rejected(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('10.0000');
        $action = app(CreateReservationAction::class);
        $action->execute($owner, $organization, $warehouse, $variant, '2.0000', 'order', 501);

        $this->expectException(ValidationException::class);
        $action->execute($owner, $organization, $warehouse, $variant, '3.0000', 'order', 501);
    }

    public function test_decimal_quantities_are_preserved_through_reserve_release_cycle(): void
    {
        [$owner, $organization, $warehouse, $variant] = $this->inventory('1.2345');
        $reservation = $this->reserve($owner, $organization, $warehouse, $variant, '0.1234');
        $this->assertSame('1.1111', $this->balance($organization, $warehouse, $variant)->available);
        app(ReleaseReservationAction::class)->execute($owner, $organization, $reservation);
        $this->assertSame('1.2345', $this->balance($organization, $warehouse, $variant)->available);
    }

    /** @return array{User, Organization, Warehouse, ProductVariant} */
    private function inventory(string $quantity): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, $quantity);

        return [$owner, $organization, $warehouse, $variant];
    }
}
