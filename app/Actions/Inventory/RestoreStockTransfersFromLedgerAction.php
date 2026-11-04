<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\TransferRequestStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;

/**
 * Explicit repair: rebuild the `stock_transfers` record of a COMPLETED
 * physical transfer whose record was removed while its ledger movements were
 * kept (selective data reset before the "transfers" category protected them —
 * e.g. TRF-000004).
 *
 * Only the ledger is used as evidence — never a transfer request: a TRF
 * number exists in `inventory_movements` only because CreateStockTransferAction
 * moved the stock. A group is rebuilt only if it is exactly what that action
 * writes: one source and one destination warehouse, and for every variant
 * `transfer_out` / `transfer_in` pairs of identical quantity. Anything else is
 * refused and reported, never guessed. The movements, balances and quantities
 * are not touched; the record and lines point at the existing movements. A
 * still-existing request whose receipt audit names this TRF is re-linked.
 *
 * `$apply = false` reports without writing. Idempotent.
 */
class RestoreStockTransfersFromLedgerAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{restorable: list<array<string,mixed>>, refused: list<array{number:string, reason:string}>, applied: bool} */
    public function execute(User $actor, Organization $organization, bool $apply): array
    {
        abort_unless(
            $actor->hasPermission($organization, 'inventory.transfer')
            && ((int) $organization->owner_id === (int) $actor->getKey() || $actor->hasPermission($organization, 'system.data_reset')),
            403,
        );

        return DB::transaction(function () use ($actor, $organization, $apply) {
            [$restorable, $refused] = $this->candidates((int) $organization->getKey());
            if ($apply) {
                foreach ($restorable as &$group) {
                    $group['stock_transfer_id'] = $this->restore($actor, $organization, $group);
                }
                unset($group);
            }

            return ['restorable' => $restorable, 'refused' => $refused, 'applied' => $apply];
        });
    }

    /** @return array{0: list<array<string,mixed>>, 1: list<array{number:string, reason:string}>} */
    private function candidates(int $organizationId): array
    {
        $linked = DB::table('stock_transfer_lines')->where('organization_id', $organizationId)
            ->select('source_out_movement_id as id')
            ->union(DB::table('stock_transfer_lines')->where('organization_id', $organizationId)->select('destination_in_movement_id as id'));
        $existing = DB::table('stock_transfers')->where('organization_id', $organizationId)->select('transfer_number');

        $movements = DB::table('inventory_movements')
            ->where('organization_id', $organizationId)
            ->where('reference_type', 'inventory_transfer')
            ->whereIn('movement_type', [InventoryMovementType::TransferOut->value, InventoryMovementType::TransferIn->value])
            ->where('reference', 'like', 'TRF-%')
            ->whereNotIn('reference', $existing)
            ->whereNotIn('id', DB::query()->fromSub($linked, 'linked')->select('id'))
            ->lockForUpdate()
            ->orderBy('id')
            ->get(['id', 'warehouse_id', 'product_variant_id', 'movement_type', 'quantity', 'reason', 'performed_by_user_id', 'created_at', 'reference']);

        $restorable = [];
        $refused = [];
        foreach ($movements->groupBy('reference') as $number => $group) {
            $number = (string) $number;
            if (! preg_match('/\ATRF-\d{6,}\z/', $number)) {
                continue; // not a stock-transfer number: never was a transfer record
            }
            $outs = $group->where('movement_type', InventoryMovementType::TransferOut->value);
            $ins = $group->where('movement_type', InventoryMovementType::TransferIn->value);
            $sources = $outs->pluck('warehouse_id')->unique();
            $destinations = $ins->pluck('warehouse_id')->unique();
            if ($outs->isEmpty() || $ins->isEmpty() || $sources->count() !== 1 || $destinations->count() !== 1 || $sources->first() === $destinations->first()) {
                $refused[] = ['number' => $number, 'reason' => 'source / destination movements incomplete or ambiguous'];

                continue;
            }

            $lines = [];
            $error = null;
            foreach ($outs->groupBy('product_variant_id') as $variantId => $variantOuts) {
                $variantIns = $ins->where('product_variant_id', $variantId)->values();
                $variantOuts = $variantOuts->values();
                if ($variantOuts->count() !== $variantIns->count()) {
                    $error = "variant {$variantId}: unpaired movements";
                    break;
                }
                foreach ($variantOuts as $i => $out) {
                    $in = $variantIns[$i];
                    $quantity = InventoryQuantity::normalize(ltrim((string) $out->quantity, '-'));
                    if (InventoryQuantity::compare((string) $out->quantity, '0') >= 0
                        || InventoryQuantity::compare($quantity, (string) $in->quantity) !== 0) {
                        $error = "variant {$variantId}: out {$out->quantity} ≠ in {$in->quantity}";
                        break 2;
                    }
                    $lines[] = ['product_variant_id' => (int) $variantId, 'quantity' => $quantity, 'out_id' => (int) $out->id, 'in_id' => (int) $in->id];
                }
            }
            if ($error === null && $ins->pluck('product_variant_id')->diff($outs->pluck('product_variant_id'))->isNotEmpty()) {
                $error = 'an incoming movement has no outgoing counterpart';
            }
            if ($error !== null) {
                $refused[] = ['number' => $number, 'reason' => $error];

                continue;
            }

            $first = $outs->first();
            $restorable[] = [
                'number' => $number,
                'source_warehouse_id' => (int) $sources->first(),
                'destination_warehouse_id' => (int) $destinations->first(),
                'transferred_at' => (string) $first->created_at,
                'performed_by_user_id' => $first->performed_by_user_id !== null ? (int) $first->performed_by_user_id : null,
                'reason' => (string) $first->reason,
                'unit_count' => array_reduce($lines, fn (string $c, array $l) => InventoryQuantity::add($c, $l['quantity']), InventoryQuantity::ZERO),
                'lines' => $lines,
            ];
        }

        return [$restorable, $refused];
    }

    /** @param array<string,mixed> $group */
    private function restore(User $actor, Organization $organization, array $group): int
    {
        $transfer = new StockTransfer;
        $transfer->organization_id = $organization->getKey();
        $transfer->source_warehouse_id = $group['source_warehouse_id'];
        $transfer->destination_warehouse_id = $group['destination_warehouse_id'];
        $transfer->transfer_number = $group['number'];
        $transfer->status = 'completed';
        $transfer->reason = $group['reason'];
        $transfer->product_count = count($group['lines']);
        $transfer->unit_count = $group['unit_count'];
        $transfer->performed_by_user_id = $group['performed_by_user_id'];
        $transfer->transferred_at = $group['transferred_at'];
        $transfer->save();

        foreach ($group['lines'] as $line) {
            $row = new StockTransferLine;
            $row->organization_id = $organization->getKey();
            $row->product_variant_id = $line['product_variant_id'];
            $row->quantity = $line['quantity'];
            $row->source_out_movement_id = $line['out_id'];
            $row->destination_in_movement_id = $line['in_id'];
            $transfer->lines()->save($row);
        }

        // A request that still exists and whose receipt named this TRF gets its link back.
        $requestIds = AuditLog::query()->where('organization_id', $organization->getKey())
            ->where('event', 'transfer_request.received')
            ->where('auditable_type', (new TransferRequest)->getMorphClass())
            ->get(['auditable_id', 'new_values'])
            ->filter(fn (AuditLog $log) => ($log->new_values['stock_transfer_number'] ?? null) === $group['number'])
            ->pluck('auditable_id');
        $relinked = TransferRequest::query()->where('organization_id', $organization->getKey())
            ->whereIn('id', $requestIds)->whereNull('stock_transfer_id')
            ->where('status', TransferRequestStatus::Received->value)
            ->pluck('request_number')->all();
        if ($relinked !== []) {
            TransferRequest::query()->where('organization_id', $organization->getKey())->whereIn('request_number', $relinked)
                ->update(['stock_transfer_id' => $transfer->getKey()]);
        }

        $this->audit->record('stock_transfer.restored_from_ledger', $actor, $organization, auditable: $transfer, newValues: [
            'transfer_number' => $group['number'],
            'source_warehouse_id' => $group['source_warehouse_id'],
            'destination_warehouse_id' => $group['destination_warehouse_id'],
            'unit_count' => $group['unit_count'],
            'transferred_at' => $group['transferred_at'],
            'movement_ids' => array_merge(array_column($group['lines'], 'out_id'), array_column($group['lines'], 'in_id')),
            'relinked_requests' => $relinked,
        ]);

        return (int) $transfer->getKey();
    }
}
