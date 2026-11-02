<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\Organization;
use App\Models\TransferRequest;
use App\Models\TransferRequestLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use App\Services\TransferRequestNumberGenerator;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * On-demand minimum-stock sweep for one operational warehouse. The candidate
 * set (see ShowroomReplenishmentPlanner::candidateVariantIds) is every explicit
 * (warehouse, variant) override plus — when a positive default minimum is
 * configured — every active same-organisation variant that currently holds
 * transferable company stock, even if it has never had a balance row in this
 * warehouse (§5). Deficits are computed in three batched queries, not N per
 * variant. Self-deduping — an already active replenishment request keeps the
 * deficit at zero (§9).
 *
 * Determinism: the whole read-decide-write cycle runs inside ONE transaction
 * holding the organization's planning lock, so a double click, a second
 * manager or a POS confirmation running at the same moment can never decide
 * from the same snapshot and stack the same deficit twice. Cancelled requests
 * are never counted (only requested / preparing / shipped are "incoming"), and
 * sources are capped at their transferable stock (available minus what other
 * active requests already promise).
 *
 * @return list<int>  ids of the transfer requests created or extended
 */
class PlanShowroomReplenishmentAction
{
    use AuthorizesInventoryAction;

    public function __construct(
        private readonly ShowroomReplenishmentPlanner $planner,
        private readonly TransferRequestNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Organization $organization, Warehouse $warehouse): array
    {
        $this->authorizeInventory($actor, $organization, 'inventory.transfer_requests.manage');
        abort_unless($warehouse->organization_id === $organization->getKey(), 404);

        if (! $this->planner->isEnabled($organization->getKey(), $warehouse->getKey())) {
            throw ValidationException::withMessages([
                'warehouse' => 'Le réassort automatique est désactivé pour cet entrepôt.',
            ]);
        }

        $touched = [];

        DB::transaction(function () use ($actor, $organization, $warehouse, &$touched) {
            $this->planner->lockPlanning($organization->getKey());

            $variantIds = $this->planner->candidateVariantIds($organization->getKey(), $warehouse->getKey());
            $deficits = $this->planner->deficits($organization->getKey(), $warehouse->getKey(), $variantIds);
            ksort($deficits);

            foreach ($deficits as $variantId => $deficit) {
                foreach ($this->planner->sourcesFor($organization->getKey(), $warehouse->getKey(), (int) $variantId, $deficit) as $source) {
                    $request = $this->replenishmentRequest($actor, $organization, (int) $source['warehouse_id'], $warehouse->getKey());
                    $this->addLine($request, (int) $variantId, $source['quantity']);
                    $touched[$request->getKey()] = $request;

                    $this->audit->record('auto_replenishment.created', $actor, $organization, auditable: $request, newValues: [
                        'request_number' => $request->request_number,
                        'source_warehouse_id' => (int) $source['warehouse_id'],
                        'destination_warehouse_id' => $warehouse->getKey(),
                        'product_variant_id' => (int) $variantId,
                        'quantity' => $source['quantity'],
                        'reason' => TransferRequestReason::MinimumReplenishment->value,
                    ]);
                }
            }

            // Any open editor on a request we just extended must reload.
            foreach ($touched as $request) {
                TransferRequest::query()->whereKey($request->getKey())->increment('lock_version');
            }
        });

        return array_map('intval', array_keys($touched));
    }

    private function replenishmentRequest(User $actor, Organization $organization, int $sourceId, int $destinationId): TransferRequest
    {
        $existing = TransferRequest::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('sales_order_id')
            ->where('source_warehouse_id', $sourceId)
            ->where('destination_warehouse_id', $destinationId)
            ->where('status', TransferRequestStatus::Requested->value)
            // Only extend a purely automatic replenishment request. Merging into
            // a request that carries other demand (manual lines…) made the
            // sweep's own lines smaller (that demand counted as incoming), and
            // cancelling "the generated request" then also cancelled the other
            // demand — so the next run produced larger lines for the same
            // inventory (the reported 1 → 2). Replenishment lines merged here
            // accumulate on the same line, so cancel + regenerate is exact.
            ->whereDoesntHave('lines', fn ($query) => $query->where('reason', '!=', TransferRequestReason::MinimumReplenishment->value))
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $request = new TransferRequest;
        $request->organization_id = $organization->getKey();
        $request->request_number = $this->numbers->next($organization);
        $request->source_warehouse_id = $sourceId;
        $request->destination_warehouse_id = $destinationId;
        $request->status = TransferRequestStatus::Requested;
        $request->requested_by_user_id = $actor->getKey();
        $request->requested_at = now();
        $request->save();

        return $request;
    }

    private function addLine(TransferRequest $request, int $variantId, string $quantity): void
    {
        $line = TransferRequestLine::query()
            ->where('organization_id', $request->organization_id)
            ->where('transfer_request_id', $request->getKey())
            ->where('product_variant_id', $variantId)
            ->where('reason', TransferRequestReason::MinimumReplenishment->value)
            ->first();

        if ($line) {
            // The existing line was already counted as incoming when the
            // deficit was computed (under the planning lock), so the deficit
            // is exactly the missing top-up: adding it is not double counting.
            $line->quantity = InventoryQuantity::add($line->quantity, $quantity);
            $line->save();

            return;
        }

        $line = new TransferRequestLine;
        $line->organization_id = $request->organization_id;
        $line->transfer_request_id = $request->getKey();
        $line->product_variant_id = $variantId;
        $line->quantity = InventoryQuantity::normalize($quantity);
        $line->reason = TransferRequestReason::MinimumReplenishment;
        $line->save();
    }
}
