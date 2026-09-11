<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\InventoryReservationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Enums\TransferRequestStatus;
use App\Models\InventoryReservation;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly InventoryReservationManager $inventory,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly CancelTransferRequestAction $transferRequests,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, SalesOrder $order, ?string $reason = null): SalesOrder
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.cancel');

        return DB::transaction(function () use ($actor, $order, $reason) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status === SalesOrderStatus::Cancelled) {
                throw ValidationException::withMessages(['order' => 'The order is already cancelled.']);
            }
            if ($order->invoices()->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value])->exists()) {
                throw ValidationException::withMessages(['order' => 'Active Invoice documents must be resolved before cancelling the Sales Order; issued Invoices require a future Credit Note workflow.']);
            }
            if (Decimal::compare($this->payments->paidAmount($order), '0.0000') > 0) {
                throw ValidationException::withMessages(['order' => 'Posted Payments must be reversed before cancelling the Sales Order.']);
            }
            if ($order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled) {
                throw ValidationException::withMessages(['order' => 'Fulfilled or partially fulfilled orders require a returns workflow.']);
            }
            if ($order->status === SalesOrderStatus::Confirmed && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A cancellation reason is required for a confirmed order.']);
            }

            // §22 — resolve supplier procurements by state before touching stock.
            // Not-yet-ordered ones are cancelled with the Order. An already
            // ordered one must not silently vanish: it blocks until the operator
            // receives it or cancels it explicitly. A received one keeps its real
            // company stock — its Order earmark is released below with the other
            // reservations, so that quantity simply becomes available stock.
            $procurements = SalesOrderProcurement::query()
                ->where('organization_id', $order->organization_id)
                ->where('sales_order_id', $order->getKey())
                ->whereNotIn('status', [SupplierProcurementStatus::Cancelled->value, SupplierProcurementStatus::Completed->value])
                ->lockForUpdate()->get();
            foreach ($procurements as $procurement) {
                if ($procurement->status === SupplierProcurementStatus::Ordered) {
                    throw ValidationException::withMessages([
                        'order' => "Approvisionnement {$procurement->procurement_number} déjà commandé au fournisseur. Réceptionnez-le ou annulez-le explicitement avant d’annuler la commande.",
                    ]);
                }
                if ($procurement->status === SupplierProcurementStatus::Received) {
                    $this->audit->record('procurement.order_cancelled_stock_retained', $actor, $order->organization, $order->store, $procurement, newValues: [
                        'procurement_number' => $procurement->procurement_number,
                        'sales_order_id' => $order->getKey(),
                        'quantity' => $procurement->quantity,
                        'receiving_warehouse_id' => $procurement->receiving_warehouse_id,
                    ]);

                    continue;
                }
                $oldProcurementStatus = $procurement->status;
                $procurement->status = SupplierProcurementStatus::Cancelled;
                $procurement->cancellation_reason = $reason ?: 'Commande client annulée';
                $procurement->cancelled_by_user_id = $actor->getKey();
                $procurement->save();
                $this->audit->record('procurement.cancelled', $actor, $order->organization, $order->store, $procurement, oldValues: [
                    'status' => $oldProcurementStatus->value,
                ], newValues: [
                    'procurement_number' => $procurement->procurement_number,
                    'sales_order_id' => $order->getKey(),
                    'supplier_id' => $procurement->supplier_id,
                    'quantity' => $procurement->quantity,
                    'reason' => $procurement->cancellation_reason,
                ]);
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

                // Drop the order portion of any not-yet-shipped internal transfer
                // request. A shipped/received request is left intact — its stock
                // is already moving and needs an operational return, not a
                // silent cancel. Minimum-replenishment demand survives.
                $pending = TransferRequest::query()
                    ->where('organization_id', $order->organization_id)
                    ->where('sales_order_id', $order->getKey())
                    ->whereIn('status', [TransferRequestStatus::Requested->value, TransferRequestStatus::Preparing->value])
                    ->get();
                foreach ($pending as $request) {
                    $this->transferRequests->execute($actor, $request, 'Commande annulée', orderPortionOnly: true);
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
