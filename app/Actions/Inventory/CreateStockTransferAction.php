<?php

namespace App\Actions\Inventory;

use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\StockTransferNumberGenerator;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;

class CreateStockTransferAction
{
    public function __construct(
        private readonly TransferInventoryAction $transfers,
        private readonly StockTransferNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{variant: ProductVariant, quantity: int|string|float}>  $lines
     */
    public function execute(
        User $actor,
        Organization $organization,
        Warehouse $source,
        Warehouse $destination,
        array $lines,
        string $reason,
    ): StockTransfer {
        return DB::transaction(function () use ($actor, $organization, $source, $destination, $lines, $reason) {
            $transferNumber = $this->numbers->next($organization);
            $movements = $this->transfers->executeMany(
                $actor,
                $organization,
                $source,
                $destination,
                $lines,
                $reason,
                $transferNumber,
            );

            $transfer = new StockTransfer;
            $transfer->organization_id = $organization->getKey();
            $transfer->source_warehouse_id = $source->getKey();
            $transfer->destination_warehouse_id = $destination->getKey();
            $transfer->transfer_number = $transferNumber;
            $transfer->status = 'completed';
            $transfer->reason = $reason;
            $transfer->product_count = count($movements);
            $transfer->unit_count = collect($movements)
                ->reduce(fn(string $carry, array $line) => InventoryQuantity::add($carry, $line['quantity']), InventoryQuantity::ZERO);
            $transfer->performed_by_user_id = $actor->getKey();
            $transfer->transferred_at = now();
            $transfer->save();

            foreach ($movements as $line) {
    $transferLine = new StockTransferLine;

    $transferLine->organization_id = $organization->getKey();
    $transferLine->product_variant_id = $line['variant']->getKey();
    $transferLine->quantity = $line['quantity'];
    $transferLine->source_out_movement_id = $line['out']->getKey();
    $transferLine->destination_in_movement_id = $line['in']->getKey();

    $transfer->lines()->save($transferLine);
}

            $this->audit->record('stock_transfer.created', $actor, $organization, auditable: $transfer, newValues: [
                'transfer_number' => $transferNumber,
                'source_warehouse_id' => $source->getKey(),
                'destination_warehouse_id' => $destination->getKey(),
                'product_count' => $transfer->product_count,
                'unit_count' => $transfer->unit_count,
            ]);

            return $transfer;
        });
    }
}
