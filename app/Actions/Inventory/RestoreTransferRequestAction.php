<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\SalesOrderStatus;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Enums\WarehouseStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\TransferRequest;
use App\Models\TransferRequestLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Rétablir la demande" — bring back a request cancelled by mistake, but only
 * when doing so is still safe RIGHT NOW. The request goes back to `requested`
 * (it must be prepared again); its cancellation stays in the audit log and in
 * `cancelled_at` / `cancellation_reason`, and `restored_at` / `restored_by`
 * record the restoration.
 *
 * Never restorable: anything ever shipped or received.
 * Blocked (with an explanation, so the user can create a new request instead):
 *  - an inactive source or destination;
 *  - ORDER_FULFILLMENT lines whose customer order is no longer confirmed or no
 *    longer holds that reserved quantity at the source;
 *  - free lines the source can no longer cover (stock moved or promised to
 *    another request since the cancellation);
 *  - MINIMUM_REPLENISHMENT lines already covered again (a fresh replenishment
 *    was generated after the cancellation) — restoring would duplicate demand;
 *  - MANUAL lines already re-requested on another active request.
 */
class RestoreTransferRequestAction
{
    use AuthorizesInventoryAction;

    public function __construct(
        private readonly ShowroomReplenishmentPlanner $planner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Read-only eligibility check (also used to render the button state).
     *
     * @return array{eligible: bool, blockers: list<string>}
     */
    public function assess(TransferRequest $request): array
    {
        $request->loadMissing(['lines.productVariant.product', 'sourceWarehouse', 'destinationWarehouse']);
        $blockers = [];
        $org = (int) $request->organization_id;

        if ($request->status !== TransferRequestStatus::Cancelled) {
            return ['eligible' => false, 'blockers' => ['Seule une demande annulée peut être rétablie.']];
        }
        if ($request->shipped_at !== null || $request->received_at !== null || $request->stock_transfer_id !== null) {
            return ['eligible' => false, 'blockers' => ['Une demande déjà expédiée ou réceptionnée ne peut jamais être rétablie.']];
        }
        if ($request->lines->isEmpty()) {
            return ['eligible' => false, 'blockers' => ['La demande ne contient plus aucune ligne.']];
        }
        if ($request->sourceWarehouse?->status !== WarehouseStatus::Active || $request->destinationWarehouse?->status !== WarehouseStatus::Active) {
            $blockers[] = 'La source ou la destination n’est plus active.';
        }

        $name = fn (TransferRequestLine $l) => trim(($l->productVariant?->product?->name ?? 'Article').($l->productVariant?->label ? ' — '.$l->productVariant->label : ''));

        // --- customer-order lines -----------------------------------------
        $orderLines = $request->lines->filter(fn ($l) => $l->reason === TransferRequestReason::OrderFulfillment);
        if ($orderLines->isNotEmpty()) {
            $order = $request->sales_order_id
                ? SalesOrder::query()->where('organization_id', $org)->whereKey($request->sales_order_id)->first(['id', 'order_number', 'status'])
                : null;
            if (! $order || $order->status !== SalesOrderStatus::Confirmed) {
                $blockers[] = 'La commande client liée n’est plus confirmée : ses lignes ne peuvent pas être rétablies.';
            } else {
                foreach ($orderLines as $line) {
                    $held = SalesOrderInventoryAllocation::query()
                        ->where('organization_id', $org)
                        ->where('warehouse_id', $request->source_warehouse_id)
                        ->whereHas('salesOrderLine', fn ($q) => $q->where('sales_order_id', $order->getKey())->where('product_variant_id', $line->product_variant_id))
                        ->get(['quantity'])
                        ->reduce(fn (string $c, $a) => InventoryQuantity::add($c, $a->quantity), InventoryQuantity::ZERO);
                    if (InventoryQuantity::compare($held, $line->quantity) < 0) {
                        $blockers[] = "{$name($line)} : la commande {$order->order_number} ne réserve plus cette quantité à la source.";
                    }
                }
            }
        }

        // --- free lines ---------------------------------------------------
        $free = $request->lines->reject(fn ($l) => $l->reason === TransferRequestReason::OrderFulfillment);
        $totals = [];
        foreach ($free as $line) {
            $totals[(int) $line->product_variant_id] = InventoryQuantity::add($totals[(int) $line->product_variant_id] ?? InventoryQuantity::ZERO, $line->quantity);
        }
        if ($totals !== []) {
            $stock = $this->planner->transferableMap($org, (int) $request->source_warehouse_id, array_keys($totals));
            foreach ($free->unique('product_variant_id') as $line) {
                $variantId = (int) $line->product_variant_id;
                if (InventoryQuantity::compare($totals[$variantId], $stock[$variantId]['transferable']) > 0) {
                    $blockers[] = "{$name($line)} : stock transférable insuffisant à la source (".$this->fmt($stock[$variantId]['transferable']).' disponible(s), '.$this->fmt($totals[$variantId]).' demandé(s)).';
                }
            }
        }

        $mr = $free->filter(fn ($l) => $l->reason === TransferRequestReason::MinimumReplenishment);
        if ($mr->isNotEmpty()) {
            $deficits = $this->planner->deficits($org, (int) $request->destination_warehouse_id, $mr->pluck('product_variant_id')->map(fn ($v) => (int) $v)->all());
            foreach ($mr as $line) {
                $deficit = $deficits[(int) $line->product_variant_id] ?? InventoryQuantity::ZERO;
                if (InventoryQuantity::compare($line->quantity, $deficit) > 0) {
                    $covering = $this->activeRequestsFor($request, (int) $line->product_variant_id, ShowroomReplenishmentPlanner::FREE_REASONS);
                    $blockers[] = "{$name($line)} : le minimum est déjà couvert".($covering ? " par {$covering}" : '').' — rétablir créerait un réassort en double.';
                }
            }
        }

        foreach ($free->filter(fn ($l) => $l->reason === TransferRequestReason::Manual) as $line) {
            $covering = $this->activeRequestsFor($request, (int) $line->product_variant_id, [TransferRequestReason::Manual->value], since: true);
            if ($covering) {
                $blockers[] = "{$name($line)} : déjà redemandé dans {$covering}.";
            }
        }

        return ['eligible' => $blockers === [], 'blockers' => array_values(array_unique($blockers))];
    }

    public function execute(User $actor, TransferRequest $request): TransferRequest
    {
        $organization = $request->organization;
        $this->authorizeInventory($actor, $organization, 'inventory.transfer_requests.manage');

        return DB::transaction(function () use ($actor, $organization, $request) {
            $this->planner->lockPlanning($organization->getKey());

            $request = TransferRequest::query()->where('organization_id', $organization->getKey())
                ->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status === TransferRequestStatus::Requested && $request->restored_at !== null) {
                return $request; // repeated submit — already restored
            }

            $assessment = $this->assess($request);
            if (! $assessment['eligible']) {
                throw ValidationException::withMessages(['restore' => $assessment['blockers']]);
            }

            $request->status = TransferRequestStatus::Requested;
            $request->prepared_by_user_id = null;
            $request->prepared_at = null;
            $request->restored_by_user_id = $actor->getKey();
            $request->restored_at = now();
            $request->lock_version = (int) $request->lock_version + 1;
            $request->save();

            $this->audit->record('transfer_request.restored', $actor, $organization, auditable: $request, oldValues: [
                'status' => TransferRequestStatus::Cancelled->value,
                'cancelled_at' => $request->cancelled_at?->toIso8601String(),
                'cancellation_reason' => $request->cancellation_reason,
            ], newValues: [
                'request_number' => $request->request_number,
                'status' => TransferRequestStatus::Requested->value,
            ]);

            return $request;
        });
    }

    /** @param list<string> $reasons */
    private function activeRequestsFor(TransferRequest $request, int $variantId, array $reasons, bool $since = false): ?string
    {
        $numbers = TransferRequest::query()
            ->where('organization_id', $request->organization_id)
            ->where('destination_warehouse_id', $request->destination_warehouse_id)
            ->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)
            ->whereKeyNot($request->getKey())
            ->when($since && $request->cancelled_at, fn ($q) => $q->where('created_at', '>=', $request->cancelled_at))
            ->whereHas('lines', fn ($q) => $q->where('product_variant_id', $variantId)->whereIn('reason', $reasons))
            ->orderBy('id')->limit(3)->pluck('request_number')->all();

        return $numbers === [] ? null : implode(', ', $numbers);
    }

    private function fmt(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.');
    }
}
