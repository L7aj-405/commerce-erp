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

class CancelSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(private readonly InventoryReservationManager $inventory, private readonly AuditLogger $audit) {}

    public function execute(User $actor, SalesOrder $order, ?string $reason = null): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.cancel');

        return DB::transaction(function () use ($actor, $order, $reason) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status === SalesOrderStatus::Cancelled) {
                throw ValidationException::withMessages(['order' => 'The order is already cancelled.']);
            }
            if ($order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled) {
                throw ValidationException::withMessages(['order' => 'Fulfilled or partially fulfilled orders require a returns workflow.']);
            }
            if ($order->status === SalesOrderStatus::Confirmed && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A cancellation reason is required for a confirmed order.']);
            }
            $oldStatus = $order->status;
            if ($oldStatus === SalesOrderStatus::Confirmed) {
                $allocationReservationIds = $order->lines()->with('allocations')->get()
                    ->flatMap(fn ($line) => $line->allocations->pluck('inventory_reservation_id'));
                if ($allocationReservationIds->contains(null)) {
                    throw ValidationException::withMessages(['order' => 'Every catalog allocation must have an active reservation before cancellation.']);
                }
                $reservations = InventoryReservation::query()
                    ->where('organization_id', $order->organization_id)
                    ->whereHas('salesOrderAllocation.salesOrderLine', fn ($query) => $query->where('sales_order_id', $order->getKey()))
                    ->where('status', InventoryReservationStatus::Active->value)
                    ->with(['warehouse', 'productVariant'])->orderBy('warehouse_id')->orderBy('product_variant_id')->get();
                $expectedReservations = $allocationReservationIds->unique();
                if ($reservations->count() !== $expectedReservations->count()) {
                    throw ValidationException::withMessages(['order' => 'Every catalog allocation must have an active reservation before cancellation.']);
                }
                foreach ($reservations as $reservation) {
                    $this->inventory->release($actor, $order->organization, $reservation);
                }
            }
            $order->status = SalesOrderStatus::Cancelled;
            $order->cancelled_at = now();
            $order->cancelled_by_user_id = $actor->getKey();
            $order->cancellation_reason = $reason;
            $order->save();
            $this->audit->record('sales_order.cancelled', $actor, $order->organization, $order->store, $order, oldValues: ['status' => $oldStatus->value], newValues: [
                'order_number' => $order->order_number, 'status' => SalesOrderStatus::Cancelled->value,
                'reason' => $reason, 'total_incl_tax' => $order->total_incl_tax,
            ]);

            return $order;
        });
    }
}
