<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceLocker;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReservationAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AuditLogger $audit) {}

    public function execute(
        User $actor,
        Organization $organization,
        Warehouse $warehouse,
        ProductVariant $variant,
        int|float|string $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reference = null,
        mixed $expiresAt = null,
    ): InventoryReservation {
        $this->authorizeInventory($actor, $organization, 'inventory.reserve');
        $this->validateInventoryIdentity($organization, $warehouse, $variant);
        $quantity = InventoryQuantity::positive($quantity);

        return DB::transaction(function () use ($actor, $organization, $warehouse, $variant, $quantity, $referenceType, $referenceId, $reference, $expiresAt) {
            $balance = $this->balances->lock($organization, $warehouse, $variant);

            if ($referenceType !== null && $referenceId !== null) {
                $existing = InventoryReservation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('warehouse_id', $warehouse->getKey())
                    ->where('product_variant_id', $variant->getKey())
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->lockForUpdate()
                    ->first();
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
                'quantity' => $quantity, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'reference' => $reference,
            ]);

            return $reservation;
        });
    }
}
