<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Actions\Inventory\Concerns\ValidatesTransferRequestLines;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\TransferRequest;
use App\Models\TransferRequestLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShowroomReplenishmentPlanner;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit the lines of a transfer request while it is still `requested`: change
 * quantities, remove lines, add products. Only free-stock lines
 * (MINIMUM_REPLENISHMENT / MANUAL) are editable. ORDER_FULFILLMENT lines carry
 * a customer's reserved stock and are never reduced or removed here.
 *
 * Payload lines: `{id?, product_variant_id, quantity}`. A line with an `id`
 * updates that existing free line (its reason is kept for traceability); a
 * line without `id` is a new MANUAL line; an existing free line missing from
 * the payload is removed.
 *
 * Optimistic concurrency: the caller quotes the `lock_version` it loaded; any
 * other change in between (a colleague's edit, a sweep extending the request,
 * preparation) bumps it and the stale edit is rejected — never merged blindly.
 */
class UpdateTransferRequestLinesAction
{
    use AuthorizesInventoryAction;
    use ValidatesTransferRequestLines;

    public function __construct(
        private readonly ShowroomReplenishmentPlanner $planner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{id?: int|string|null, product_variant_id: int|string, quantity: int|float|string}>  $lines
     */
    public function execute(User $actor, TransferRequest $request, int $expectedVersion, array $lines, ?string $note = null): TransferRequest
    {
        $organization = $request->organization;
        $this->authorizeInventory($actor, $organization, 'inventory.transfer_requests.manage');

        return DB::transaction(function () use ($actor, $organization, $request, $expectedVersion, $lines, $note) {
            $this->planner->lockPlanning($organization->getKey());

            $request = TransferRequest::query()->where('organization_id', $organization->getKey())
                ->whereKey($request->getKey())->lockForUpdate()->with(['lines', 'sourceWarehouse'])->firstOrFail();

            if ($request->status !== TransferRequestStatus::Requested) {
                throw ValidationException::withMessages(['status' => 'Seule une demande « demandée » peut être modifiée. En préparation, ajustez les quantités lors de la confirmation d’expédition.']);
            }
            if ((int) $request->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => 'Cette demande a été modifiée entre-temps. Rechargez la page pour repartir de la version actuelle.']);
            }

            $existing = $request->lines->keyBy('id');
            $orderLines = $request->lines->filter(fn ($l) => $l->reason === TransferRequestReason::OrderFulfillment);

            // --- normalise the payload ------------------------------------
            $updates = [];   // line id => qty
            $creates = [];   // variant id => qty
            $indexByVariant = [];

            // Validated per line (not via normaliseLines): a variant may
            // legitimately appear twice when it has both a replenishment and a
            // manual line.
            foreach (array_values($lines) as $index => $line) {
                $variantId = (int) ($line['product_variant_id'] ?? 0);
                $quantity = InventoryQuantity::positive((string) ($line['quantity'] ?? '0'), "lines.{$index}.quantity");
                $indexByVariant[$variantId] ??= $index;
                $lineId = isset($line['id']) && $line['id'] !== null && $line['id'] !== '' ? (int) $line['id'] : null;

                if ($lineId !== null) {
                    $current = $existing->get($lineId);
                    if (! $current) {
                        throw ValidationException::withMessages(["lines.{$index}.id" => 'Cette ligne n’existe plus. Rechargez la page.']);
                    }
                    if ($current->reason === TransferRequestReason::OrderFulfillment) {
                        if (InventoryQuantity::compare($current->quantity, $quantity) !== 0) {
                            throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Quantité liée à une commande client : elle ne peut pas être modifiée ici.']);
                        }
                        continue;
                    }
                    if ((int) $current->product_variant_id !== $variantId) {
                        throw ValidationException::withMessages(["lines.{$index}.product_variant_id" => 'L’article d’une ligne existante ne peut pas être changé.']);
                    }
                    $updates[$lineId] = $quantity;
                } else {
                    if (isset($creates[$variantId]) || $existing->contains(fn ($l) => (int) $l->product_variant_id === $variantId && $l->reason === TransferRequestReason::Manual)) {
                        throw ValidationException::withMessages(["lines.{$index}.product_variant_id" => 'Cet article figure déjà dans la demande.']);
                    }
                    $creates[$variantId] = $quantity;
                }
            }

            if ($creates !== []) {
                $this->normaliseLines($organization, collect($creates)->map(fn ($q, $v) => ['product_variant_id' => $v, 'quantity' => $q])->values()->all());
            }

            $freeLines = $request->lines->reject(fn ($l) => $l->reason === TransferRequestReason::OrderFulfillment);
            $removed = $freeLines->reject(fn ($l) => array_key_exists($l->getKey(), $updates));

            if ($updates === [] && $creates === [] && $orderLines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Une demande doit garder au moins un article. Pour l’abandonner, annulez-la avec un motif.']);
            }

            // --- server-side stock check (per variant, all free lines) -----
            $freeTotals = [];
            foreach ($updates as $lineId => $qty) {
                $v = (int) $existing[$lineId]->product_variant_id;
                $freeTotals[$v] = InventoryQuantity::add($freeTotals[$v] ?? InventoryQuantity::ZERO, $qty);
            }
            foreach ($creates as $v => $qty) {
                $freeTotals[$v] = InventoryQuantity::add($freeTotals[$v] ?? InventoryQuantity::ZERO, $qty);
            }
            $this->assertTransferable($organization, $request->sourceWarehouse, $freeTotals, $request->getKey(), $indexByVariant);

            // --- apply ----------------------------------------------------
            $before = $request->lines->map(fn ($l) => [
                'id' => $l->getKey(), 'product_variant_id' => (int) $l->product_variant_id, 'quantity' => $l->quantity, 'reason' => $l->reason->value,
            ])->values()->all();

            foreach ($removed as $line) {
                $line->delete();
            }
            foreach ($updates as $lineId => $qty) {
                $line = $existing[$lineId];
                if (InventoryQuantity::compare($line->quantity, $qty) !== 0) {
                    $line->quantity = $qty;
                    $line->save();
                }
            }
            foreach ($creates as $variantId => $qty) {
                $line = new TransferRequestLine;
                $line->organization_id = $organization->getKey();
                $line->transfer_request_id = $request->getKey();
                $line->product_variant_id = $variantId;
                $line->quantity = $qty;
                $line->reason = TransferRequestReason::Manual;
                $line->save();
            }

            if ($note !== null) {
                $request->note = trim($note) !== '' ? trim($note) : null;
            }
            $request->lock_version = (int) $request->lock_version + 1;
            $request->save();
            $request->load('lines');

            $this->audit->record('transfer_request.lines_updated', $actor, $organization, auditable: $request, oldValues: ['lines' => $before], newValues: [
                'request_number' => $request->request_number,
                'lines' => $request->lines->map(fn ($l) => [
                    'id' => $l->getKey(), 'product_variant_id' => (int) $l->product_variant_id, 'quantity' => $l->quantity, 'reason' => $l->reason->value,
                ])->values()->all(),
            ]);

            return $request;
        });
    }
}
