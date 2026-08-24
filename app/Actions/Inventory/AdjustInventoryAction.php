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

class AdjustInventoryAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, InventoryMovementType $type, int|float|string $quantity, string $reason, ?string $reference = null): InventoryMovement
    {
        $this->authorizeInventory($actor, $organization, 'inventory.adjust');
        $this->validateInventoryIdentity($organization, $warehouse, $variant);
        if (! in_array($type, [InventoryMovementType::AdjustmentIn, InventoryMovementType::AdjustmentOut], true)) {
            throw ValidationException::withMessages(['type' => 'Only inventory adjustments are accepted by this action.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        $quantity = InventoryQuantity::positive($quantity);

        return DB::transaction(function () use ($actor, $organization, $warehouse, $variant, $type, $quantity, $reason, $reference) {
            $balance = $this->balances->lock($organization, $warehouse, $variant);
            $before = $balance->on_hand;
            if ($type === InventoryMovementType::AdjustmentOut) {
                $available = InventoryQuantity::subtract($balance->on_hand, $balance->reserved);
                if (InventoryQuantity::compare($available, $quantity) < 0) {
                    throw ValidationException::withMessages(['quantity' => 'The requested quantity exceeds available stock.']);
                }
                $after = InventoryQuantity::subtract($before, $quantity);
                $signedQuantity = '-'.$quantity;
            } else {
                $after = InventoryQuantity::add($before, $quantity);
                $signedQuantity = $quantity;
            }

            $movement = new InventoryMovement;
            $movement->organization_id = $organization->getKey();
            $movement->warehouse_id = $warehouse->getKey();
            $movement->product_variant_id = $variant->getKey();
            $movement->movement_type = $type;
            $movement->quantity = $signedQuantity;
            $movement->quantity_before = $before;
            $movement->quantity_after = $after;
            $movement->reference = $reference;
            $movement->reason = $reason;
            $movement->performed_by_user_id = $actor->getKey();
            $movement->save();

            $balance->on_hand = $after;
            $balance->save();
            $this->audit->record('inventory.adjusted', $actor, $organization, auditable: $movement, newValues: [
                'warehouse_id' => $warehouse->getKey(), 'product_variant_id' => $variant->getKey(),
                'type' => $type->value, 'quantity' => $signedQuantity, 'quantity_before' => $before,
                'quantity_after' => $after, 'reason' => $reason, 'reference' => $reference,
            ]);

            return $movement;
        });
    }
}
