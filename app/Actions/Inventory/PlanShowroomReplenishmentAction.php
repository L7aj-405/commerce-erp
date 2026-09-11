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

        $variantIds = $this->planner->candidateVariantIds($organization->getKey(), $warehouse->getKey());
        $deficits = $this->planner->deficits($organization->getKey(), $warehouse->getKey(), $variantIds);

        $touched = [];

        DB::transaction(function () use ($actor, $organization, $warehouse, $deficits, &$touched) {
            foreach ($deficits as $variantId => $deficit) {
                foreach ($this->planner->sourcesFor($organization->getKey(), $warehouse->getKey(), (int) $variantId, $deficit) as $source) {
                    $request = $this->replenishmentRequest($actor, $organization, (int) $source['warehouse_id'], $warehouse->getKey());
                    $this->addLine($request, (int) $variantId, $source['quantity']);
                    $touched[$request->getKey()] = $request->getKey();

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
        });

        return array_values($touched);
    }

    private function replenishmentRequest(User $actor, Organization $organization, int $sourceId, int $destinationId): TransferRequest
    {
        $existing = TransferRequest::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('sales_order_id')
            ->where('source_warehouse_id', $sourceId)
            ->where('destination_warehouse_id', $destinationId)
            ->where('status', TransferRequestStatus::Requested->value)
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
