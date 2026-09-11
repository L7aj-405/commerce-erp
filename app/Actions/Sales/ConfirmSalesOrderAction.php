<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\CreateOrderTransferRequestsAction;
use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Services\PosStockAllocator;
use App\Services\SalesOrderTotalsCalculator;
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
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, SalesOrder $order): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.confirm');

        return DB::transaction(function () use ($actor, $order) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Draft) {
                throw ValidationException::withMessages(['order' => 'Only a draft order can be confirmed.']);
            }
            $this->totals->recalculate($order);
            $lines = $order->lines()->with(['allocations.warehouse', 'productVariant', 'procurements'])->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'At least one line is required before confirmation.']);
            }
            $isManual = $order->source !== SalesOrderSource::Pos;
            $allocations = collect();
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
                        $supplierCovered = Decimal::add($supplierCovered, $procurement->quantity);
                    } elseif (! $procurement->status->isTerminal()) {
                        $hasUnconfirmedProcurement = true;
                    }
                }
                if ($hasUnconfirmedProcurement) {
                    throw ValidationException::withMessages([
                        'lines' => 'Un approvisionnement fournisseur de cette commande n’est pas confirmé (ou est indisponible). Confirmez la disponibilité fournisseur, changez de fournisseur, ou couvrez la ligne depuis le stock société avant de confirmer.',
                    ]);
                }
                $companyTarget = Decimal::normalize($line->quantity);
                if (Decimal::compare($supplierCovered, '0') > 0) {
                    $companyTarget = Decimal::subtract($companyTarget, $supplierCovered);
                    if (Decimal::compare($companyTarget, '0') < 0) {
                        throw ValidationException::withMessages([
                            'lines' => 'La quantité approvisionnée auprès du fournisseur dépasse la quantité de la ligne.',
                        ]);
                    }
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
                if ($isManual || Decimal::compare($supplierCovered, '0') > 0) {
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
                    $line->setRelation('allocations', $line->allocations()->with('warehouse')->get());
                }

                $allocated = '0.0000';
                foreach ($line->allocations as $allocation) {
                    $allocated = Decimal::add($allocated, $allocation->quantity);
                    if ($allocation->inventory_reservation_id !== null) {
                        throw ValidationException::withMessages(['lines' => 'A draft allocation is already linked to a reservation.']);
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

            $order->status = SalesOrderStatus::Confirmed;
            $order->confirmed_at = now();
            $order->confirmed_by_user_id = $actor->getKey();
            $order->save();
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
