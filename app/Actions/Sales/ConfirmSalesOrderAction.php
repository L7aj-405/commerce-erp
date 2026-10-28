<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\CreateOrderTransferRequestsAction;
use App\Actions\Commissions\GenerateSalesOrderCommissionEntriesAction;
use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\InventoryReservationStatus;
use App\Models\SalesOrder;
use App\Models\InventoryReservation;
use App\Models\ProcurementReceipt;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Services\PosStockAllocator;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\SalesOrderRevisionSnapshot;
use App\Services\SalesOrderTotalsCalculator;
use App\Services\SalespersonEligibilityService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly SalesOrderTotalsCalculator $totals,
        private readonly InventoryReservationManager $inventory,
        private readonly PosStockAllocator $stock,
        private readonly CreateOrderTransferRequestsAction $transferRequests,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly SalesOrderRevisionSnapshot $revisionSnapshots,
        private readonly SnapshotSalesOrderLineMarginsAction $marginSnapshots,
        private readonly SalespersonEligibilityService $salespersons,
        private readonly GenerateSalesOrderCommissionEntriesAction $commissions,
        private readonly SalesOrderProcurementPlanner $procurementPlanner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Active reservations of goods received for this line while the order was a
     * draft (ReceiveProcurementAction), keyed by warehouse. A reservation of
     * another article, or holding more than was received, is a conflict.
     *
     * @return \Illuminate\Support\Collection<int, InventoryReservation>
     */
    private function receiptHoldings(SalesOrder $order, SalesOrderLine $line): \Illuminate\Support\Collection
    {
        $procurements = $line->procurements->filter(fn (SalesOrderProcurement $p) => Decimal::compare((string) $p->received_quantity, '0') > 0);
        if ($procurements->isEmpty()) {
            return collect();
        }
        $reservations = InventoryReservation::query()->where('organization_id', $order->organization_id)
            ->where('reference_type', SalesOrderProcurement::class)->whereIn('reference_id', $procurements->modelKeys())
            ->where('status', InventoryReservationStatus::Active->value)->lockForUpdate()->orderBy('id')->get();
        $held = $reservations->reduce(fn (string $sum, InventoryReservation $r) => Decimal::add($sum, (string) $r->quantity), '0.0000');
        $received = $procurements->reduce(fn (string $sum, SalesOrderProcurement $p) => Decimal::add($sum, (string) $p->received_quantity), '0.0000');
        if ($reservations->contains(fn (InventoryReservation $r) => (int) $r->product_variant_id !== (int) $line->product_variant_id)
            || Decimal::compare($held, $received) > 0
            || $reservations->pluck('warehouse_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Réservation de réception fournisseur incohérente pour cette ligne.']);
        }

        return $reservations->keyBy(fn (InventoryReservation $r) => (int) $r->warehouse_id);
    }

    public function execute(User $actor, SalesOrder $order): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.confirm');

        return DB::transaction(function () use ($actor, $order) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Draft) {
                throw ValidationException::withMessages(['order' => 'Only a draft order can be confirmed.']);
            }
            $this->totals->recalculate($order);
            $lines = $order->lines()->with(['allocations.warehouse', 'productVariant', 'procurements'])->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'At least one line is required before confirmation.']);
            }

            if ($order->salesperson_id !== null) {
                $salesperson = $this->salespersons->resolve($order->organization_id, $order->salesperson_id);
                $order->salesperson_name_snapshot = $salesperson->name;
            }

            $revision = $order->currentRevision;
            if ($revision && $revision->status === 'in_progress') {
                $paid = $this->payments->paidAmount($order);
                if (Decimal::compare($paid, $order->total_incl_tax) > 0) {
                    throw ValidationException::withMessages([
                        'order' => 'Le nouveau total est inférieur aux paiements déjà encaissés. Cette différence nécessite un avoir ou un remboursement.',
                    ]);
                }
            }
            // Procurement readiness (SalesOrderProcurementPlanner, the same view as the
            // order page, the MCP preview and the attention engine): goods derived
            // from the quotation must have physically arrived — supplier
            // confirmation, purchase order or expected delivery never suffice.
            $blockers = $this->procurementPlanner->confirmationBlockers($order);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['procurement' => array_column($blockers, 'message')]);
            }

            $isManual = $order->source !== SalesOrderSource::Pos;
            $allocations = collect();
            $increases = [];
            foreach ($lines as $line) {
                // A still-unresolved tax must be settled before the Order becomes
                // an official commercial commitment — never confirmed as 0%.
                if ($line->tax_unresolved) {
                    throw ValidationException::withMessages([
                        'lines' => 'Une ligne a une TVA non résolue. Choisissez un taux de TVA sur la ligne avant de confirmer.',
                    ]);
                }

                if ($line->line_type === SalesOrderLineType::Custom) {
                    if ($line->allocations->isNotEmpty()) {
                        throw ValidationException::withMessages(['lines' => 'Custom lines cannot allocate Inventory.']);
                    }

                    continue;
                }
                if (! $line->productVariant) {
                    throw ValidationException::withMessages(['lines' => 'A catalog line is missing its Product Variant.']);
                }

                // §10 — the supplier-procured portion of this line. Only a
                // procurement whose supplier availability is CONFIRMED (or that
                // has since been ordered / received) counts; a still-pending or
                // unavailable one leaves the line under-covered and blocks.
                $supplierCovered = '0.0000';
                $hasUnconfirmedProcurement = false;
                foreach ($line->procurements as $procurement) {
                    if ($procurement->status->coversSalesLine()) {
                        // Only the not-yet-received part is a supplier commitment;
                        // received goods are held by their reservation (adopted below).
                        $supplierCovered = Decimal::add($supplierCovered, Decimal::subtract((string) $procurement->quantity, (string) $procurement->received_quantity));
                    } elseif (! $procurement->status->isTerminal()) {
                        $hasUnconfirmedProcurement = true;
                    }
                }
                if ($hasUnconfirmedProcurement) {
                    throw ValidationException::withMessages([
                        'lines' => 'Un approvisionnement fournisseur de cette commande n’est pas confirmé (ou est indisponible). Confirmez la disponibilité fournisseur, changez de fournisseur, ou couvrez la ligne depuis le stock société avant de confirmer.',
                    ]);
                }
                // Supplier goods received while the order was a draft: already in
                // stock and reserved for this line, one reservation per warehouse.
                $held = $this->receiptHoldings($order, $line);
                $receivedHeld = $held->reduce(fn (string $sum, InventoryReservation $reservation) => Decimal::add($sum, (string) $reservation->quantity), '0.0000');
                $companyTarget = Decimal::subtract(Decimal::subtract(Decimal::normalize($line->quantity), $supplierCovered), $receivedHeld);
                if (Decimal::compare($companyTarget, '0') < 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'La quantité approvisionnée auprès du fournisseur dépasse la quantité de la ligne.',
                    ]);
                }

                // Draft sourcing is provisional. Rebuild the COMPANY_STOCK
                // portion from scratch against CURRENT availability, under the
                // same authoritative company-wide allocator the POS uses, right
                // before the balance locks below. A genuine company-wide shortage
                // on that portion throws here and the whole confirmation rolls
                // back. Reservations are only ever taken on company stock — the
                // supplier portion is a procurement commitment, not stock.
                //
                // POS instant sales with no supplier procurement keep their
                // entry-time allocation untouched here (their strict backorder
                // check lives in CreatePosSaleAction), but the moment a line is
                // partly supplier-sourced the company portion must be re-derived
                // authoritatively — company stock may have moved since the cart
                // was opened.
                if ($isManual || Decimal::compare($supplierCovered, '0') > 0 || $held->isNotEmpty()) {
                    // A draft allocation never carries a reservation: one that does
                    // belongs to something else and is never silently discarded.
                    if ($line->allocations->contains(fn (SalesOrderInventoryAllocation $allocation) => $allocation->inventory_reservation_id !== null)) {
                        throw ValidationException::withMessages(['lines' => 'A draft allocation is already linked to a reservation.']);
                    }
                    $preferred = $line->allocations->first()?->warehouse
                        ?? $this->stock->preferredWarehouse($order, $line->productVariant);
                    $line->allocations()->delete();
                    if (Decimal::compare($companyTarget, '0') > 0) {
                        if (! $preferred) {
                            throw ValidationException::withMessages([
                                'lines' => 'Aucun entrepôt actif disponible pour approvisionner cette commande.',
                            ]);
                        }
                        foreach ($this->stock->allocations($order, $preferred, $line->productVariant, $companyTarget, $line) as $prepared) {
                            $rebuilt = new SalesOrderInventoryAllocation;
                            $rebuilt->organization_id = $order->organization_id;
                            $rebuilt->sales_order_line_id = $line->getKey();
                            $rebuilt->warehouse_id = $prepared['warehouse']->getKey();
                            $rebuilt->quantity = $prepared['quantity'];
                            $rebuilt->save();
                        }
                    }
                    // Adopt the receipt reservations: preserved as they are (never
                    // released, never reserved twice), each now owned by the line's
                    // allocation at its warehouse. Company stock allocated at the
                    // same warehouse joins that reservation (increased below).
                    foreach ($held as $warehouseId => $reservation) {
                        $allocation = $line->allocations()->where('warehouse_id', $warehouseId)->first();
                        if ($allocation) {
                            $increases[] = [$allocation, (string) $allocation->quantity];
                            $allocation->quantity = Decimal::add((string) $allocation->quantity, (string) $reservation->quantity);
                        } else {
                            $allocation = new SalesOrderInventoryAllocation;
                            $allocation->organization_id = $order->organization_id;
                            $allocation->sales_order_line_id = $line->getKey();
                            $allocation->warehouse_id = $warehouseId;
                            $allocation->quantity = $reservation->quantity;
                        }
                        $allocation->inventory_reservation_id = $reservation->getKey();
                        $allocation->save();
                        $reservation->reference_type = SalesOrderInventoryAllocation::class;
                        $reservation->reference_id = $allocation->getKey();
                        $reservation->save();
                        ProcurementReceipt::query()->where('organization_id', $order->organization_id)
                            ->where('inventory_reservation_id', $reservation->getKey())->whereNull('sales_order_inventory_allocation_id')
                            ->update(['sales_order_inventory_allocation_id' => $allocation->getKey()]);
                    }
                    $line->setRelation('allocations', $line->allocations()->with('warehouse')->get());
                }

                $adopted = $held->keys()->all();
                $allocated = '0.0000';
                foreach ($line->allocations as $allocation) {
                    $allocated = Decimal::add($allocated, $allocation->quantity);
                    if ($allocation->inventory_reservation_id !== null) {
                        if (! in_array((int) $allocation->warehouse_id, $adopted, true)) {
                            throw ValidationException::withMessages(['lines' => 'A draft allocation is already linked to a reservation.']);
                        }

                        continue; // receipt reservation, preserved
                    }
                    $allocations->push($allocation);
                }
                if (Decimal::compare(Decimal::add($allocated, $supplierCovered), $line->quantity) !== 0) {
                    if ($hasUnconfirmedProcurement || Decimal::compare($supplierCovered, '0') > 0) {
                        throw ValidationException::withMessages([
                            'lines' => 'Ligne non couverte : stock société alloué + approvisionnement fournisseur confirmé doivent égaler la quantité demandée.',
                        ]);
                    }
                    throw ValidationException::withMessages(['lines' => 'Catalog allocation quantities must equal their sales line quantity.']);
                }
            }

            foreach ($allocations->sortBy(fn (SalesOrderInventoryAllocation $allocation) => sprintf('%020d-%020d-%020d', $allocation->warehouse_id, $allocation->salesOrderLine->product_variant_id, $allocation->id)) as $allocation) {
                $line = $allocation->salesOrderLine;
                $reservation = $this->inventory->reserve(
                    $actor, $order->organization, $allocation->warehouse, $line->productVariant,
                    $allocation->quantity, SalesOrderInventoryAllocation::class, $allocation->getKey(), $order->order_number,
                );
                $allocation->inventory_reservation_id = $reservation->getKey();
                $allocation->save();
            }
            // Company stock allocated next to adopted receipt goods joins their reservation.
            foreach ($increases as [$allocation, $companyQuantity]) {
                if (Decimal::compare($companyQuantity, '0') > 0) {
                    $reservation = InventoryReservation::query()->where('organization_id', $order->organization_id)->whereKey($allocation->inventory_reservation_id)->firstOrFail();
                    $this->inventory->increase($actor, $order->organization, $reservation, $companyQuantity);
                }
            }

            // Cost is frozen at the same atomic boundary as the commercial
            // commitment, after all authoritative line calculations succeed.
            $this->marginSnapshots->execute($order, $lines);

            $order->status = SalesOrderStatus::Confirmed;
            $order->confirmed_at = now();
            // W4.5 payment term, stamped once (a revision re-confirming the order keeps it).
            $order->due_date ??= $order->sale_date->copy()->addDays(max(0, (int) config('payments.sales_order_payment_term_days', 7)));
            $order->confirmed_by_user_id = $actor->getKey();
            $order->save();

            // Commission snapshots belong to the same atomic boundary as the
            // confirmed sale and its immutable C2/C3 snapshots. The action is
            // source-key idempotent, including controlled correction cycles.
            $this->commissions->execute($actor, $order->load(['organization', 'store', 'lines']));

            if ($revision && $revision->status === 'in_progress') {
                $this->payments->recalculate($order);
                $revision->after_snapshot = $this->revisionSnapshots->make($order->fresh(['lines.allocations.warehouse', 'customer']));
                $revision->status = 'completed';
                $revision->completed_by_user_id = $actor->getKey();
                $revision->completed_at = now();
                $revision->save();
                $this->audit->record('sales_order.correction_completed', $actor, $order->organization, $order->store, $order, newValues: [
                    'order_number' => $order->order_number,
                    'revision_id' => $revision->getKey(),
                    'revision_number' => $revision->revision_number,
                    'before_total' => data_get($revision->before_snapshot, 'totals.total_incl_tax'),
                    'after_total' => $order->total_incl_tax,
                ]);
            }
            $this->audit->record('sales_order.confirmed', $actor, $order->organization, $order->store, $order, oldValues: ['status' => SalesOrderStatus::Draft->value], newValues: [
                'order_number' => $order->order_number, 'status' => SalesOrderStatus::Confirmed->value,
                'subtotal_excl_tax' => $order->subtotal_excl_tax, 'discount_total' => $order->discount_total,
                'tax_total' => $order->tax_total, 'total_incl_tax' => $order->total_incl_tax,
            ]);

            // Showroom POS pickup orders sourced partly from another warehouse
            // raise an internal Transfer Request (+ minimum-stock top-up) here,
            // in the same transaction. No-op for manual / delivery orders and
            // for fully-local orders. Idempotent.
            $this->transferRequests->createForConfirmedOrder($actor, $order->fresh(['lines.allocations', 'organization', 'store']));

            return $order->load('lines.allocations.inventoryReservation');
        });
    }
}
