<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Services\SalesOrderTotalsCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(private readonly SalesOrderTotalsCalculator $totals, private readonly InventoryReservationManager $inventory, private readonly AuditLogger $audit) {}

    public function execute(User $actor, SalesOrder $order): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.confirm');

        return DB::transaction(function () use ($actor, $order) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Draft) {
                throw ValidationException::withMessages(['order' => 'Only a draft order can be confirmed.']);
            }
            $this->totals->recalculate($order);
            $lines = $order->lines()->with(['allocations.warehouse', 'productVariant'])->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'At least one line is required before confirmation.']);
            }
            $allocations = collect();
            foreach ($lines as $line) {
                if ($line->line_type === SalesOrderLineType::Custom) {
                    if ($line->allocations->isNotEmpty()) {
                        throw ValidationException::withMessages(['lines' => 'Custom lines cannot allocate Inventory.']);
                    }

                    continue;
                }
                if (! $line->productVariant) {
                    throw ValidationException::withMessages(['lines' => 'A catalog line is missing its Product Variant.']);
                }
                $allocated = '0.0000';
                foreach ($line->allocations as $allocation) {
                    $allocated = Decimal::add($allocated, $allocation->quantity);
                    if ($allocation->inventory_reservation_id !== null) {
                        throw ValidationException::withMessages(['lines' => 'A draft allocation is already linked to a reservation.']);
                    }
                    $allocations->push($allocation);
                }
                if (Decimal::compare($allocated, $line->quantity) !== 0) {
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

            return $order->load('lines.allocations.inventoryReservation');
        });
    }
}
