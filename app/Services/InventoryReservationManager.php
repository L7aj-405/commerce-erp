<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\WarehouseStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryReservationManager
{
    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AuditLogger $audit) {}

    public function reserve(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, int|float|string $quantity, ?string $referenceType = null, ?int $referenceId = null, ?string $reference = null, mixed $expiresAt = null): InventoryReservation
    {
        $this->assertContext($actor, $organization);
        abort_unless($warehouse->organization_id === $organization->getKey() && $variant->organization_id === $organization->getKey(), 404);
        if ($warehouse->status !== WarehouseStatus::Active || $variant->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['warehouse_id' => 'Reservations require an active warehouse and variant.']);
        }
        $quantity = InventoryQuantity::positive($quantity);

        return DB::transaction(function () use ($actor, $organization, $warehouse, $variant, $quantity, $referenceType, $referenceId, $reference, $expiresAt) {
            $balance = $this->balances->lock($organization, $warehouse, $variant);
            if ($referenceType !== null && $referenceId !== null) {
                $existing = InventoryReservation::query()
                    ->where('organization_id', $organization->getKey())->where('warehouse_id', $warehouse->getKey())
                    ->where('product_variant_id', $variant->getKey())->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)->lockForUpdate()->first();
                if ($existing) {
                    if (InventoryQuantity::compare($existing->quantity, $quantity) !== 0) {
                        throw ValidationException::withMessages(['reference_id' => 'This reference already identifies a reservation with a different quantity.']);
                    }

                    return $existing;
                }
            }
            $available = InventoryQuantity::subtract($balance->on_hand, $balance->reserved);
            if (InventoryQuantity::compare($available, $quantity) < 0) {
                throw ValidationException::withMessages(['quantity' => 'The requested quantity exceeds available stock.']);
            }

            $reservation = new InventoryReservation;
            $reservation->organization_id = $organization->getKey();
            $reservation->warehouse_id = $warehouse->getKey();
            $reservation->product_variant_id = $variant->getKey();
            $reservation->quantity = $quantity;
            $reservation->status = InventoryReservationStatus::Active;
            $reservation->reference_type = $referenceType;
            $reservation->reference_id = $referenceId;
            $reservation->reference = $reference;
            $reservation->expires_at = $expiresAt;
            $reservation->created_by_user_id = $actor->getKey();
            $reservation->save();
            $balance->reserved = InventoryQuantity::add($balance->reserved, $quantity);
            $balance->save();
            $this->audit->record('inventory.reservation.created', $actor, $organization, auditable: $reservation, newValues: [
                'warehouse_id' => $warehouse->getKey(), 'product_variant_id' => $variant->getKey(),
                'quantity' => $quantity, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'reference' => $reference,
            ]);

            return $reservation;
        });
    }

    public function release(User $actor, Organization $organization, InventoryReservation $reservation): InventoryReservation
    {
        $this->assertContext($actor, $organization);
        abort_unless($reservation->organization_id === $organization->getKey(), 404);

        return DB::transaction(function () use ($actor, $organization, $reservation) {
            $balance = $this->balances->lock($organization, $reservation->warehouse, $reservation->productVariant);
            $reservation = InventoryReservation::query()->whereKey($reservation->getKey())
                ->where('organization_id', $organization->getKey())->lockForUpdate()->firstOrFail();
            if ($reservation->status !== InventoryReservationStatus::Active) {
                throw ValidationException::withMessages(['reservation' => 'Only an active reservation can be released.']);
            }
            if (InventoryQuantity::compare($balance->reserved, $reservation->quantity) < 0) {
                throw ValidationException::withMessages(['reservation' => 'The reserved balance is inconsistent with this reservation.']);
            }
            $balance->reserved = InventoryQuantity::subtract($balance->reserved, $reservation->quantity);
            $balance->save();
            $reservation->status = InventoryReservationStatus::Released;
            $reservation->save();
            $this->audit->record('inventory.reservation.released', $actor, $organization, auditable: $reservation, oldValues: ['status' => InventoryReservationStatus::Active->value], newValues: [
                'status' => InventoryReservationStatus::Released->value, 'warehouse_id' => $reservation->warehouse_id,
                'product_variant_id' => $reservation->product_variant_id, 'quantity' => $reservation->quantity,
            ]);

            return $reservation;
        });
    }

    public function consume(User $actor, Organization $organization, InventoryReservation $reservation): InventoryReservation
    {
        $this->assertContext($actor, $organization);
        abort_unless($reservation->organization_id === $organization->getKey(), 404);

        return DB::transaction(function () use ($actor, $organization, $reservation) {
            $balance = $this->balances->lock($organization, $reservation->warehouse, $reservation->productVariant);
            $reservation = InventoryReservation::query()->whereKey($reservation->getKey())
                ->where('organization_id', $organization->getKey())->lockForUpdate()->firstOrFail();
            if ($reservation->status !== InventoryReservationStatus::Active) {
                throw ValidationException::withMessages(['reservation' => 'Only an active reservation can be consumed.']);
            }
            if (InventoryQuantity::compare($balance->reserved, $reservation->quantity) < 0 || InventoryQuantity::compare($balance->on_hand, $reservation->quantity) < 0) {
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
            $movement->metadata = ['source_reference_type' => $reservation->reference_type, 'source_reference_id' => $reservation->reference_id];
            $movement->save();
            $balance->on_hand = $after;
            $balance->reserved = InventoryQuantity::subtract($balance->reserved, $reservation->quantity);
            $balance->save();
            $reservation->status = InventoryReservationStatus::Consumed;
            $reservation->save();
            $this->audit->record('inventory.reservation.consumed', $actor, $organization, auditable: $reservation, oldValues: ['status' => InventoryReservationStatus::Active->value], newValues: [
                'status' => InventoryReservationStatus::Consumed->value, 'warehouse_id' => $reservation->warehouse_id,
                'product_variant_id' => $reservation->product_variant_id, 'quantity' => $reservation->quantity,
                'movement_id' => $movement->getKey(),
            ]);

            return $reservation;
        });
    }

    private function assertContext(User $actor, Organization $organization): void
    {
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $organization->status === 'active'
            && $actor->organizationMemberships()->where('organization_id', $organization->getKey())->where('status', 'active')->exists(),
            403
        );
    }
}
