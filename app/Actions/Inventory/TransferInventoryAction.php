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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransferInventoryAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly InventoryBalanceLocker $balances, private readonly AuditLogger $audit) {}

    /** @return array{out: InventoryMovement, in: InventoryMovement} */
    public function execute(User $actor, Organization $organization, Warehouse $source, Warehouse $destination, ProductVariant $variant, int|float|string $quantity, string $reason, ?string $reference = null): array
    {
        $this->authorizeInventory($actor, $organization, 'inventory.adjust');
        $this->validateInventoryIdentity($organization, $source, $variant);
        $this->validateInventoryIdentity($organization, $destination, $variant);
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'Source and destination warehouses must be different.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        $quantity = InventoryQuantity::positive($quantity);
        $reference ??= 'TRANSFER-'.Str::uuid();

        return DB::transaction(function () use ($actor, $organization, $source, $destination, $variant, $quantity, $reason, $reference) {
            $warehouses = collect([$source, $destination])->sortBy(fn (Warehouse $warehouse) => $warehouse->getKey());
            $locked = [];
            foreach ($warehouses as $warehouse) {
                $locked[$warehouse->getKey()] = $this->balances->lock($organization, $warehouse, $variant);
            }
            $sourceBalance = $locked[$source->getKey()];
            $destinationBalance = $locked[$destination->getKey()];
            $available = InventoryQuantity::subtract($sourceBalance->on_hand, $sourceBalance->reserved);
            if (InventoryQuantity::compare($available, $quantity) < 0) {
                throw ValidationException::withMessages(['quantity' => 'The requested quantity exceeds available stock in the source warehouse.']);
            }

            $sourceBefore = $sourceBalance->on_hand;
            $sourceAfter = InventoryQuantity::subtract($sourceBefore, $quantity);
            $destinationBefore = $destinationBalance->on_hand;
            $destinationAfter = InventoryQuantity::add($destinationBefore, $quantity);
            $out = $this->movement($actor, $organization, $source, $variant, InventoryMovementType::TransferOut, '-'.$quantity, $sourceBefore, $sourceAfter, $reason, $reference);
            $in = $this->movement($actor, $organization, $destination, $variant, InventoryMovementType::TransferIn, $quantity, $destinationBefore, $destinationAfter, $reason, $reference);

            $sourceBalance->on_hand = $sourceAfter;
            $sourceBalance->save();
            $destinationBalance->on_hand = $destinationAfter;
            $destinationBalance->save();
            $this->audit->record('inventory.transferred', $actor, $organization, auditable: $out, newValues: [
                'source_warehouse_id' => $source->getKey(),
                'destination_warehouse_id' => $destination->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'reason' => $reason,
                'reference' => $reference,
                'out_movement_id' => $out->getKey(),
                'in_movement_id' => $in->getKey(),
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    private function movement(User $actor, Organization $organization, Warehouse $warehouse, ProductVariant $variant, InventoryMovementType $type, string $quantity, string $before, string $after, string $reason, string $reference): InventoryMovement
    {
        $movement = new InventoryMovement;
        $movement->organization_id = $organization->getKey();
        $movement->warehouse_id = $warehouse->getKey();
        $movement->product_variant_id = $variant->getKey();
        $movement->movement_type = $type;
        $movement->quantity = $quantity;
        $movement->quantity_before = $before;
        $movement->quantity_after = $after;
        $movement->reference_type = 'inventory_transfer';
        $movement->reference = $reference;
        $movement->reason = $reason;
        $movement->performed_by_user_id = $actor->getKey();
        $movement->save();

        return $movement;
    }
}
