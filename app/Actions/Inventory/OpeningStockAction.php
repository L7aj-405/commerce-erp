<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryBalanceLocker;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningStockAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, int|float|string $quantity, ?string $reason = null, ?string $reference = null): InventoryMovement
    {
        $this->authorizeInventory($actor, $organization, 'inventory.opening');
        $this->validateInventoryIdentity($organization, $warehouse, $variant);
        $quantity = InventoryQuantity::positive($quantity);

        return DB::transaction(function () use ($actor, $organization, $warehouse, $variant, $quantity, $reason, $reference) {
            $balance = $this->balances->lock($organization, $warehouse, $variant);
            $alreadyInitialized = InventoryMovement::query()
                ->where('organization_id', $organization->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->exists();
            if ($alreadyInitialized) {
                throw ValidationException::withMessages(['quantity' => 'Opening stock is allowed only before the first physical movement. Use an adjustment instead.']);
            }

            $before = $balance->on_hand;
            $after = InventoryQuantity::add($before, $quantity);
            $movement = $this->movement($actor, $organization, $warehouse, $variant, InventoryMovementType::Opening, $quantity, $before, $after, $reason, $reference);
            $balance->on_hand = $after;
            $balance->save();

            $this->audit->record('inventory.opening_stock', $actor, $organization, auditable: $movement, newValues: [
                'warehouse_id' => $warehouse->getKey(), 'product_variant_id' => $variant->getKey(),
                'quantity' => $quantity, 'quantity_before' => $before, 'quantity_after' => $after,
                'reason' => $reason, 'reference' => $reference,
            ]);

            return $movement;
        });
    }

    private function movement(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, InventoryMovementType $type, string $quantity, string $before, string $after, ?string $reason, ?string $reference): InventoryMovement
    {
        $movement = new InventoryMovement;
        $movement->organization_id = $organization->getKey();
        $movement->warehouse_id = $warehouse->getKey();
        $movement->product_variant_id = $variant->getKey();
        $movement->movement_type = $type;
        $movement->quantity = $quantity;
        $movement->quantity_before = $before;
        $movement->quantity_after = $after;
        $movement->reference = $reference;
        $movement->reason = $reason;
        $movement->performed_by_user_id = $actor->getKey();
        $movement->save();

        return $movement;
    }
}
