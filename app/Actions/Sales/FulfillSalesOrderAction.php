<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\InventoryReservation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(private readonly InventoryReservationManager $inventory, private readonly AuditLogger $audit) {}

    public function execute(User $actor, SalesOrder $order): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.fulfill');

        return DB::transaction(function () use ($actor, $order) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Confirmed || $order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled) {
                throw ValidationException::withMessages(['order' => 'Only a confirmed, unfulfilled order can be fulfilled.']);
            }
            $allocationReservationIds = $order->lines()->with('allocations')->get()->flatMap(fn ($line) => $line->allocations->pluck('inventory_reservation_id'));
            if ($allocationReservationIds->contains(null)) {
                throw ValidationException::withMessages(['order' => 'Every catalog allocation must have an active reservation.']);
            }
            $reservationIds = $allocationReservationIds->unique()->values();
            $reservations = InventoryReservation::query()->where('organization_id', $order->organization_id)
                ->whereIn('id', $reservationIds)->with(['warehouse', 'productVariant'])
                ->orderBy('warehouse_id')->orderBy('product_variant_id')->get();
            if ($reservations->count() !== $reservationIds->count() || $reservations->contains(fn ($reservation) => $reservation->status !== InventoryReservationStatus::Active)) {
                throw ValidationException::withMessages(['order' => 'Every catalog allocation must have an active reservation.']);
            }
            foreach ($reservations as $reservation) {
                $this->inventory->consume($actor, $order->organization, $reservation);
            }
            $order->fulfillment_status = SalesOrderFulfillmentStatus::Fulfilled;
            $order->fulfilled_at = now();
            $order->fulfilled_by_user_id = $actor->getKey();
            $order->save();
            $this->audit->record('sales_order.fulfilled', $actor, $order->organization, $order->store, $order, oldValues: ['fulfillment_status' => SalesOrderFulfillmentStatus::Unfulfilled->value], newValues: [
                'order_number' => $order->order_number, 'fulfillment_status' => SalesOrderFulfillmentStatus::Fulfilled->value,
                'total_incl_tax' => $order->total_incl_tax,
            ]);

            return $order;
        });
    }
}
