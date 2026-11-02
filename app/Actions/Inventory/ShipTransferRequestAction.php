<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Actions\Inventory\Concerns\SnapshotsTransferDriver;
use App\Actions\Inventory\Concerns\ValidatesTransferRequestLines;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * preparing → shipped. Still logistics only — the ledger transfer happens on
 * receipt. A shipped request can no longer be cancelled without an explicit
 * operational reverse/return.
 *
 * The optional `$shipment` payload carries the chauffeur / vehicle details
 * entered on the "Expédier" confirmation, captured here as an immutable
 * snapshot alongside shipped_at / shipped_by.
 *
 * Preparation review: `$confirmedLines` (line id => quantity actually packed)
 * lets the preparer confirm what really leaves the source instead of shipping
 * every suggested unit. Every line must be confirmed explicitly. Free lines
 * (replenishment / manual) may be reduced — down to 0, which drops the line —
 * and the original demand is kept in `requested_quantity`. ORDER_FULFILLMENT
 * quantities belong to a customer and must be shipped as reserved. Confirmed
 * free quantities are re-validated against the source's current stock.
 */
class ShipTransferRequestAction
{
    use AuthorizesInventoryAction;
    use SnapshotsTransferDriver;
    use ValidatesTransferRequestLines;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ShowroomReplenishmentPlanner $planner,
    ) {}

    /**
     * @param  array<string, mixed>  $shipment
     * @param  array<int|string, int|float|string>|null  $confirmedLines  line id => quantity to send
     */
    public function execute(User $actor, TransferRequest $request, array $shipment = [], ?array $confirmedLines = null, ?int $expectedVersion = null): TransferRequest
    {
        $this->authorizeInventory($actor, $request->organization, 'inventory.transfer_requests.manage');

        return DB::transaction(function () use ($actor, $request, $shipment, $confirmedLines, $expectedVersion) {
            if ($confirmedLines !== null) {
                $this->planner->lockPlanning((int) $request->organization_id);
            }

            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()->with(['organization', 'lines', 'sourceWarehouse'])->firstOrFail();

            if ($request->status === TransferRequestStatus::Shipped && $confirmedLines !== null && $expectedVersion !== null) {
                return $request; // repeated submit of the same confirmation
            }
            if ($request->status !== TransferRequestStatus::Preparing) {
                throw ValidationException::withMessages(['status' => 'Seule une demande « en préparation » peut être expédiée.']);
            }
            if ($expectedVersion !== null && (int) $request->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => 'Cette demande a été modifiée entre-temps. Rechargez la page avant de confirmer l’expédition.']);
            }

            $adjustments = $confirmedLines !== null ? $this->applyReview($request, $confirmedLines) : [];

            $driverSnapshot = $this->applyDriverSnapshot($request, $shipment);

            $request->status = TransferRequestStatus::Shipped;
            $request->shipped_by_user_id = $actor->getKey();
            $request->shipped_at = now();
            $request->lock_version = (int) $request->lock_version + 1;
            $request->save();

            if ($adjustments !== []) {
                $this->audit->record('transfer_request.preparation_adjusted', $actor, $request->organization, auditable: $request, newValues: [
                    'request_number' => $request->request_number,
                    'adjustments' => $adjustments,
                ]);
            }

            if ($driverSnapshot !== null) {
                $this->audit->record('transfer_request.driver_assigned', $actor, $request->organization, auditable: $request, newValues: [
                    'request_number' => $request->request_number,
                    ...$driverSnapshot,
                ]);
            }

            $this->audit->record('transfer_request.shipped', $actor, $request->organization, auditable: $request, newValues: [
                'request_number' => $request->request_number,
                'source_warehouse_id' => $request->source_warehouse_id,
                'destination_warehouse_id' => $request->destination_warehouse_id,
                'driver_name' => $request->driver_name,
                'vehicle' => $request->vehicle,
                'vehicle_registration' => $request->vehicle_registration,
                'reviewed' => $confirmedLines !== null,
            ]);

            return $request;
        });
    }

    /**
     * @param  array<int|string, int|float|string>  $confirmedLines
     * @return list<array<string, mixed>>  the lines whose quantity changed
     */
    private function applyReview(TransferRequest $request, array $confirmedLines): array
    {
        $confirmed = [];
        foreach ($confirmedLines as $lineId => $quantity) {
            $confirmed[(int) $lineId] = InventoryQuantity::normalize((string) $quantity, "lines.{$lineId}");
        }

        $errors = [];
        $freeTotals = [];
        foreach ($request->lines as $line) {
            $id = (int) $line->getKey();
            if (! array_key_exists($id, $confirmed)) {
                $errors["lines.{$id}"] = 'Confirmez la quantité préparée de chaque ligne.';

                continue;
            }
            $qty = $confirmed[$id];
            if (InventoryQuantity::compare($qty, InventoryQuantity::ZERO) < 0) {
                $errors["lines.{$id}"] = 'La quantité ne peut pas être négative.';
            } elseif ($line->reason === TransferRequestReason::OrderFulfillment) {
                if (InventoryQuantity::compare($qty, $line->quantity) !== 0) {
                    $errors["lines.{$id}"] = 'Quantité réservée pour une commande client : elle doit être expédiée en totalité.';
                }
            } elseif (InventoryQuantity::compare($qty, $line->quantity) > 0) {
                $errors["lines.{$id}"] = 'La quantité envoyée ne peut pas dépasser la quantité demandée.';
            } else {
                $variantId = (int) $line->product_variant_id;
                $freeTotals[$variantId] = InventoryQuantity::add($freeTotals[$variantId] ?? InventoryQuantity::ZERO, $qty);
            }
        }

        $anything = $request->lines->contains(fn ($line) => InventoryQuantity::compare($confirmed[(int) $line->getKey()] ?? InventoryQuantity::ZERO, InventoryQuantity::ZERO) > 0);
        if ($errors === [] && ! $anything) {
            $errors['lines'] = 'Rien à expédier : annulez la demande avec un motif si plus rien ne doit partir.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $freeTotals = array_filter($freeTotals, fn ($q) => InventoryQuantity::compare($q, InventoryQuantity::ZERO) > 0);
        // Errors are keyed by line id here (the review form is per line).
        $indexByVariant = [];
        foreach ($request->lines as $line) {
            $indexByVariant[(int) $line->product_variant_id] ??= (int) $line->getKey();
        }
        $this->assertTransferable($request->organization, $request->sourceWarehouse, $freeTotals, $request->getKey(), $indexByVariant);

        $adjustments = [];
        foreach ($request->lines as $line) {
            $qty = $confirmed[(int) $line->getKey()];
            if (InventoryQuantity::compare($qty, $line->quantity) === 0) {
                continue;
            }
            $adjustments[] = [
                'product_variant_id' => (int) $line->product_variant_id,
                'reason' => $line->reason->value,
                'requested' => $line->quantity,
                'sent' => $qty,
            ];
            if (InventoryQuantity::compare($qty, InventoryQuantity::ZERO) === 0) {
                $line->delete();

                continue;
            }
            $line->requested_quantity ??= $line->quantity;
            $line->quantity = $qty;
            $line->save();
        }
        $request->load('lines');

        return $adjustments;
    }
}
