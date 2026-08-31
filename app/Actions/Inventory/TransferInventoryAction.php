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
        $reference ??= 'TRANSFER-'.Str::uuid();

        return $this->executeMany($actor, $organization, $source, $destination, [
            ['variant' => $variant, 'quantity' => $quantity],
        ], $reason, $reference)[0];
    }

    /**
     * @param  list<array{variant: ProductVariant, quantity: int|float|string}>  $lines
     * @return list<array{variant: ProductVariant, quantity: string, out: InventoryMovement, in: InventoryMovement}>
     */
    public function executeMany(User $actor, Organization $organization, Warehouse $source, Warehouse $destination, array $lines, string $reason, ?string $reference = null): array
    {
        $this->authorizeInventory($actor, $organization, 'inventory.transfer');
        $this->validateRoute($organization, $source, $destination, $reason);
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Select at least one product to transfer.']);
        }

        $prepared = collect($lines)->map(function (array $line, int $index) use ($organization, $source, $destination) {
            $variant = $line['variant'];
            $this->validateInventoryIdentity($organization, $source, $variant);
            $this->validateInventoryIdentity($organization, $destination, $variant);

            return [
                'index' => $index,
                'variant' => $variant,
                'quantity' => InventoryQuantity::positive($line['quantity'], "lines.{$index}.quantity"),
            ];
        })->sortBy(fn (array $line) => $line['variant']->getKey())->values();

        $reference ??= 'TRANSFER-'.Str::uuid();

        return DB::transaction(function () use ($actor, $organization, $source, $destination, $prepared, $reason, $reference) {
            $locked = [];

            foreach ($prepared as $line) {
                $variant = $line['variant'];
                foreach (collect([$source, $destination])->sortBy(fn (Warehouse $warehouse) => $warehouse->getKey()) as $warehouse) {
                    $balance = $this->balances->lock($organization, $warehouse, $variant);
                    $locked[$variant->getKey()][$warehouse->getKey()] = $balance;
                }
            }

            $transfers = [];

            foreach ($prepared as $line) {
                $variant = $line['variant'];
                $quantity = $line['quantity'];
                $sourceBalance = $locked[$variant->getKey()][$source->getKey()];
                $destinationBalance = $locked[$variant->getKey()][$destination->getKey()];
                $available = InventoryQuantity::subtract($sourceBalance->on_hand, $sourceBalance->reserved);

                if (InventoryQuantity::compare($available, $quantity) < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$line['index']}.quantity" => "Stock disponible insuffisant. {$this->humanQuantity($available)} unités maximum peuvent être transférées.",
                    ]);
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

                $transfers[] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'out' => $out,
                    'in' => $in,
                ];
            }

            $this->audit->record('inventory.transferred', $actor, $organization, auditable: $transfers[0]['out'], newValues: [
                'source_warehouse_id' => $source->getKey(),
                'destination_warehouse_id' => $destination->getKey(),
                'reason' => $reason,
                'reference' => $reference,
                'lines' => collect($transfers)->map(fn (array $transfer) => [
                    'product_variant_id' => $transfer['variant']->getKey(),
                    'quantity' => $transfer['quantity'],
                    'out_movement_id' => $transfer['out']->getKey(),
                    'in_movement_id' => $transfer['in']->getKey(),
                ])->all(),
            ]);

            return $transfers;
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

    private function validateRoute(Organization $organization, Warehouse $source, Warehouse $destination, string $reason): void
    {
        abort_unless(
            $source->organization_id === $organization->getKey()
            && $destination->organization_id === $organization->getKey(),
            404
        );

        if ($source->is($destination)) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'La source et la destination doivent être différentes.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Un motif est requis.']);
        }
    }

    private function humanQuantity(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.');
    }
}
