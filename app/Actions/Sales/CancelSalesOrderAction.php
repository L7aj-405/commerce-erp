<?php

namespace App\Actions\Sales;

use App\Actions\Documents\CancelDeliveryNoteDraftAction;
use App\Actions\Documents\CancelInvoiceDraftAction;
use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\DeliveryNoteStatus;
use App\Enums\InventoryReservationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Enums\TransferRequestStatus;
use App\Models\InventoryReservation;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelSalesOrderAction
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly InventoryReservationManager $inventory,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly CancelTransferRequestAction $transferRequests,
        private readonly CancelInvoiceDraftAction $invoiceDrafts,
        private readonly CancelDeliveryNoteDraftAction $deliveryNoteDrafts,
        private readonly RefundPaymentAction $refunds,
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
            if ($order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled) {
                throw ValidationException::withMessages(['order' => 'Fulfilled or partially fulfilled orders require a returns workflow.']);
            }
            if ($order->status === SalesOrderStatus::Confirmed && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A cancellation reason is required for a confirmed order.']);
            }

            $issuedInvoice = $order->invoices()
                ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value])
                ->lockForUpdate()
                ->first();
            if ($issuedInvoice) {
                throw ValidationException::withMessages([
                    'order' => 'Une facture émise existe. L’annulation nécessite un avoir.',
                ]);
            }

            $activeDeliveryNotes = $order->deliveryNotes()
                ->whereIn('status', [DeliveryNoteStatus::Draft->value, DeliveryNoteStatus::Issued->value])
                ->lockForUpdate()
                ->get();
            if ($activeDeliveryNotes->contains(fn ($note) => $note->status === DeliveryNoteStatus::Issued)) {
                throw ValidationException::withMessages([
                    'order' => 'Un bon de livraison émis existe. Cette opération relève du workflow de retour.',
                ]);
            }

            $shippedTransfer = TransferRequest::query()
                ->where('organization_id', $order->organization_id)
                ->where('sales_order_id', $order->getKey())
                ->where('status', TransferRequestStatus::Shipped->value)
                ->lockForUpdate()
                ->first();
            if ($shippedTransfer) {
                throw ValidationException::withMessages([
                    'order' => "Transfert {$shippedTransfer->request_number} déjà expédié. Réceptionnez-le avant d’annuler la commande.",
                ]);
            }

            $draftInvoices = $order->invoices()->where('status', InvoiceStatus::Draft->value)->lockForUpdate()->get();
            if ($draftInvoices->isNotEmpty() && ! $actor->hasPermission($order->organization_id, 'invoices.update_draft')) {
                abort(403, 'You are not authorized to cancel the linked draft Invoice.');
            }
            if ($activeDeliveryNotes->isNotEmpty() && ! $actor->hasPermission($order->organization_id, 'delivery_notes.update_draft')) {
                abort(403, 'You are not authorized to cancel the linked draft Delivery Note.');
            }

            $postedPayments = Payment::query()
                ->where('organization_id', $order->organization_id)
                ->where('store_id', $order->store_id)
                ->where('status', 'posted')
                ->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->getKey()))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($postedPayments->isNotEmpty() && ! $actor->hasPermission($order->organization_id, 'payments.reverse')) {
                abort(403, 'You are not authorized to refund posted Payments.');
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

            if ($orderedProcurement = $procurements->first(fn ($procurement) => $procurement->status === SupplierProcurementStatus::Ordered)) {
                throw ValidationException::withMessages([
                    'order' => "Approvisionnement {$orderedProcurement->procurement_number} déjà commandé au fournisseur. Réceptionnez-le ou annulez-le explicitement avant d’annuler la commande.",
                ]);
            }

            foreach ($draftInvoices as $invoice) {
                $this->invoiceDrafts->execute($actor, $invoice, $reason);
            }
            foreach ($activeDeliveryNotes as $note) {
                $this->deliveryNoteDrafts->execute($actor, $note, $reason);
            }
            foreach ($postedPayments as $payment) {
                $this->refunds->execute($actor, $payment, $order, (string) $reason);
            }

            foreach ($procurements as $procurement) {
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
                // request. Shipped requests were rejected during preflight;
                // received requests represent company stock and remain historical.
                // Minimum-replenishment demand survives.
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
            $financial = $this->payments->summary($order);
            $this->audit->record('sales_order.cancelled', $actor, $order->organization, $order->store, $order, oldValues: ['status' => $oldStatus->value], newValues: [
                'order_number' => $order->order_number, 'status' => SalesOrderStatus::Cancelled->value,
                'reason' => $reason, 'total_incl_tax' => $order->total_incl_tax,
                'collected_amount' => $financial['collected'],
                'refunded_amount' => $financial['refunded'],
                'net_collected_amount' => $financial['net'],
            ]);

            return $order;
        });
    }
}
