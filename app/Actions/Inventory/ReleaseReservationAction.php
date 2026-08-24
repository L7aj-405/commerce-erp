<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceLocker;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseReservationAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, InventoryReservation $reservation): InventoryReservation
    {
        $this->authorizeInventory($actor, $organization, 'inventory.release');
        abort_unless($reservation->organization_id === $organization->getKey(), 404);

        return DB::transaction(function () use ($actor, $organization, $reservation) {
            $balance = $this->balances->lock($organization, $reservation->warehouse, $reservation->productVariant);
            $reservation = InventoryReservation::query()
                ->whereKey($reservation->getKey())
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();
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
                'status' => InventoryReservationStatus::Released->value,
                'warehouse_id' => $reservation->warehouse_id,
                'product_variant_id' => $reservation->product_variant_id,
                'quantity' => $reservation->quantity,
            ]);

            return $reservation;
        });
    }
}
