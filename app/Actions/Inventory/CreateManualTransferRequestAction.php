<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Actions\Inventory\Concerns\ValidatesTransferRequestLines;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\Organization;
use App\Models\TransferRequest;
use App\Models\TransferRequestLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use App\Services\TransferRequestNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * "Créer une demande" — a warehouse manager raises a transfer request by hand.
 * Lines carry the MANUAL reason, count as incoming replenishment at the
 * destination (so the automatic sweep will not ask for the same units again)
 * and as promised stock at the source. No inventory effect until receipt.
 *
 * Retry-safe: the client sends an operation id (`idempotency_key`); a repeated
 * submit returns the request created the first time.
 */
class CreateManualTransferRequestAction
{
    use AuthorizesInventoryAction;
    use ValidatesTransferRequestLines;

    public function __construct(
        private readonly ShowroomReplenishmentPlanner $planner,
        private readonly TransferRequestNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{product_variant_id: int|string, quantity: int|float|string}>  $lines
     */
    public function execute(
        User $actor,
        Organization $organization,
        int $sourceWarehouseId,
        int $destinationWarehouseId,
        array $lines,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): TransferRequest {
        $this->authorizeInventory($actor, $organization, 'inventory.transfer_requests.manage');

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($actor, $organization, $sourceWarehouseId, $destinationWarehouseId, $lines, $note, $idempotencyKey) {
            $this->planner->lockPlanning($organization->getKey());

            if ($idempotencyKey !== null) {
                $existing = TransferRequest::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            [$source, $destination] = $this->resolveRoute($organization, $sourceWarehouseId, $destinationWarehouseId);
            $quantities = $this->normaliseLines($organization, $lines);
            $this->assertTransferable($organization, $source, $quantities);

            $request = new TransferRequest;
            $request->organization_id = $organization->getKey();
            $request->request_number = $this->numbers->next($organization);
            $request->source_warehouse_id = $source->getKey();
            $request->destination_warehouse_id = $destination->getKey();
            $request->status = TransferRequestStatus::Requested;
            $request->idempotency_key = $idempotencyKey;
            $request->note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $request->requested_by_user_id = $actor->getKey();
            $request->requested_at = now();
            $request->save();

            foreach ($quantities as $variantId => $quantity) {
                $line = new TransferRequestLine;
                $line->organization_id = $organization->getKey();
                $line->transfer_request_id = $request->getKey();
                $line->product_variant_id = $variantId;
                $line->quantity = $quantity;
                $line->reason = TransferRequestReason::Manual;
                $line->save();
            }

            $this->audit->record('transfer_request.created', $actor, $organization, auditable: $request, newValues: [
                'request_number' => $request->request_number,
                'source_warehouse_id' => $source->getKey(),
                'destination_warehouse_id' => $destination->getKey(),
                'reason' => TransferRequestReason::Manual->value,
                'note' => $request->note,
                'lines' => collect($quantities)->map(fn ($qty, $variantId) => ['product_variant_id' => $variantId, 'quantity' => $qty])->values()->all(),
            ]);

            return $request;
        });
    }
}
