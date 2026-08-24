<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceLocker;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsumeReservationAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, InventoryReservation $reservation): InventoryReservation
    {
        $this->authorizeInventory($actor, $organization, 'inventory.consume');
        abort_unless($reservation->organization_id === $organization->getKey(), 404);

        return DB::transaction(function () use ($actor, $organization, $reservation) {
            $balance = $this->balances->lock($organization, $reservation->warehouse, $reservation->productVariant);
            $reservation = InventoryReservation::query()
                ->whereKey($reservation->getKey())
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($reservation->status !== InventoryReservationStatus::Active) {
                throw ValidationException::withMessages(['reservation' => 'Only an active reservation can be consumed.']);
            }

            if (
                InventoryQuantity::compare($balance->reserved, $reservation->quantity) < 0
                || InventoryQuantity::compare($balance->on_hand, $reservation->quantity) < 0
            ) {
                throw ValidationException::withMessages(['reservation' => 'The inventory balance is inconsistent with this reservation.']);
            }

            $before = $balance->on_hand;
            $after = InventoryQuantity::subtract($before, $reservation->quantity);
            $movement = new InventoryMovement;
            $movement->organization_id = $organization->getKey();
            $movement->warehouse_id = $reservation->warehouse_id;
            $movement->product_variant_id = $reservation->product_variant_id;
            $movement->movement_type = InventoryMovementType::ReservationConsumed;
            $movement->quantity = '-'.$reservation->quantity;
            $movement->quantity_before = $before;
            $movement->quantity_after = $after;
            $movement->reference_type = InventoryReservation::class;
            $movement->reference_id = $reservation->getKey();
            $movement->reference = $reservation->reference;
            $movement->reason = 'Reservation consumed';
            $movement->performed_by_user_id = $actor->getKey();
            $movement->save();

            $balance->on_hand = $after;
            $balance->reserved = InventoryQuantity::subtract($balance->reserved, $reservation->quantity);
            $balance->save();
            $reservation->status = InventoryReservationStatus::Consumed;
            $reservation->save();

            $this->audit->record('inventory.reservation.consumed', $actor, $organization, auditable: $reservation, oldValues: ['status' => InventoryReservationStatus::Active->value], newValues: [
                'status' => InventoryReservationStatus::Consumed->value,
                'warehouse_id' => $reservation->warehouse_id,
                'product_variant_id' => $reservation->product_variant_id,
                'quantity' => $reservation->quantity,
                'movement_id' => $movement->getKey(),
            ]);

            return $reservation;
        });
    }
}
