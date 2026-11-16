<?php

namespace App\Actions\Inventory;

use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use App\Services\StockTransferNumberGenerator;
use App\Support\InventoryQuantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immediate, completed stock transfer (paired ledger movements).
 *
 * A DIRECT transfer is a voluntary commitment of source stock, so it may not
 * consume units already promised to open transfer requests (replenishment /
 * manual lines, which are not reservations): `$respectTransferPromises`.
 * Receiving a transfer request passes `false` — those units are the promised
 * ones and have physically left already, so the receipt must never be blocked.
 */
class CreateStockTransferAction
{
    public function __construct(
        private readonly TransferInventoryAction $transfers,
        private readonly StockTransferNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly ShowroomReplenishmentPlanner $planner,
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
        string $permission = 'inventory.transfer',
        bool $respectTransferPromises = true,
        ?string $clientOperationId = null,
    ): StockTransfer {
        if ($clientOperationId === null) {
            return $this->run($actor, $organization, $source, $destination, $lines, $reason, $permission, $respectTransferPromises, null, null);
        }

        // Durable idempotency: the unique (organization, client_operation_id) key arbitrates.
        $hash = $this->fingerprint($source, $destination, $lines, $reason);
        if ($existing = $this->replayed($organization, $clientOperationId, $hash)) {
            return $existing;
        }
        try {
            return $this->run($actor, $organization, $source, $destination, $lines, $reason, $permission, $respectTransferPromises, $clientOperationId, $hash);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent submit of the same operation committed first: its whole transaction
            // (movements included) is the one that counts; this one rolled back entirely.
            if ($existing = $this->replayed($organization, $clientOperationId, $hash)) {
                return $existing;
            }
            throw $exception;
        }
    }

    private function replayed(Organization $organization, string $operationId, string $hash): ?StockTransfer
    {
        $existing = StockTransfer::query()->where('organization_id', $organization->getKey())
            ->where('client_operation_id', $operationId)->first();
        if ($existing && $existing->client_operation_hash !== $hash) {
            throw ValidationException::withMessages(['client_operation_id' => 'Cet identifiant d’opération a déjà été utilisé avec des données différentes.']);
        }

        return $existing;
    }

    /** @param list<array{variant: ProductVariant, quantity: int|string|float}> $lines */
    private function fingerprint(Warehouse $source, Warehouse $destination, array $lines, string $reason): string
    {
        $normalized = collect($lines)
            ->map(fn (array $line) => [(int) $line['variant']->getKey(), InventoryQuantity::normalize((string) $line['quantity'])])
            ->sortBy(0)->values()->all();

        return hash('sha256', json_encode([(int) $source->getKey(), (int) $destination->getKey(), trim($reason), $normalized]));
    }

    /** @param list<array{variant: ProductVariant, quantity: int|string|float}> $lines */
    private function run(
        User $actor,
        Organization $organization,
        Warehouse $source,
        Warehouse $destination,
        array $lines,
        string $reason,
        string $permission,
        bool $respectTransferPromises,
        ?string $clientOperationId,
        ?string $operationHash,
    ): StockTransfer {
        return DB::transaction(function () use ($actor, $organization, $source, $destination, $lines, $reason, $permission, $respectTransferPromises, $clientOperationId, $operationHash) {
            $transferNumber = $this->numbers->next($organization);
            $movements = $this->transfers->executeMany(
                $actor,
                $organization,
                $source,
                $destination,
                $lines,
                $reason,
                $transferNumber,
                $permission,
            );

            // Transfer requests are Production-only: a Test transfer has no promises to honour.
            if ($respectTransferPromises && ! app(\App\Services\Sandbox\SandboxContext::class)->isSandbox()) {
                $this->assertPromisesStillCovered($organization, $source, $movements);
            }

            $transfer = new StockTransfer;
            $transfer->organization_id = $organization->getKey();
            $transfer->source_warehouse_id = $source->getKey();
            $transfer->destination_warehouse_id = $destination->getKey();
            $transfer->transfer_number = $transferNumber;
            $transfer->client_operation_id = $clientOperationId;
            $transfer->client_operation_hash = $operationHash;
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

    /**
     * Checked AFTER the movement, while TransferInventoryAction still holds the
     * source balance locks: the planning lock is then taken in the same order
     * as order confirmation (balances → planning), so no deadlock cycle. A
     * shortfall rolls the whole transfer back.
     *
     * @param  list<array{variant: ProductVariant, quantity: string}>  $movements
     */
    private function assertPromisesStillCovered(Organization $organization, Warehouse $source, array $movements): void
    {
        $this->planner->lockPlanning($organization->getKey());

        $variants = collect($movements)->mapWithKeys(fn ($m) => [$m['variant']->getKey() => $m['variant']]);
        $shortfalls = $this->planner->promiseShortfalls($organization->getKey(), $source->getKey(), $variants->keys()->all());
        if ($shortfalls === []) {
            return;
        }

        $errors = [];
        foreach (array_values($movements) as $index => $movement) {
            $row = $shortfalls[$movement['variant']->getKey()] ?? null;
            if ($row === null) {
                continue;
            }
            $free = InventoryQuantity::subtract(InventoryQuantity::add($row['available'], $movement['quantity']), $row['committed']);
            $free = InventoryQuantity::compare($free, InventoryQuantity::ZERO) > 0 ? $free : InventoryQuantity::ZERO;
            $errors["lines.{$index}.quantity"] = sprintf(
                'Stock promis à des demandes de transfert ouvertes (%s) : seulement %s transférable(s) directement. Modifiez ou annulez la demande d’abord.',
                implode(', ', $row['requests']),
                rtrim(rtrim($free, '0'), '.'),
            );
        }

        throw ValidationException::withMessages($errors);
    }
}
