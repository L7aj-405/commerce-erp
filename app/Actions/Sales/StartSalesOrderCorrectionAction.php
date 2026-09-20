<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\InventoryReservationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TransferRequestStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderRevision;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryReservationManager;
use App\Services\SalesOrderRevisionSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open a controlled commercial correction on an unfulfilled SalesOrder.
 *
 * The issued Invoice is never touched. The current commercial state is frozen
 * into SalesOrderRevision, unconsumed reservations are released through the
 * inventory domain, and only then is the current order projection reopened for
 * the existing draft editor/actions.
 */
class StartSalesOrderCorrectionAction
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly InventoryReservationManager $reservations,
        private readonly CancelTransferRequestAction $transferRequests,
        private readonly SalesOrderRevisionSnapshot $snapshots,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, SalesOrder $order, string $reason): SalesOrderRevision
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.update');
        $this->authorizeOrder($actor, $order, 'sales_orders.confirm');

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Un motif de correction est requis.']);
        }

        return DB::transaction(function () use ($actor, $order, $reason) {
            $order = SalesOrder::query()->where('organization_id', $order->organization_id)
                ->where('store_id', $order->store_id)->whereKey($order->getKey())
                ->lockForUpdate()->with(['lines.allocations.inventoryReservation', 'lines.allocations.warehouse', 'procurements', 'transferRequests.lines', 'organization', 'store'])
                ->firstOrFail();

            if ($order->status !== SalesOrderStatus::Confirmed || $order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled) {
                throw ValidationException::withMessages([
                    'order' => 'Une commande livrée ou exécutée nécessite un workflow de retour/avoir et ne peut pas être corrigée ici.',
                ]);
            }
            if ($order->currentRevision?->status === 'in_progress') {
                throw ValidationException::withMessages(['order' => 'Une correction commerciale est déjà en cours.']);
            }
            if ($order->invoices()->where('status', InvoiceStatus::Draft->value)->exists()) {
                throw ValidationException::withMessages([
                    'order' => 'Annulez d’abord la facture brouillon liée à cette commande avant d’ouvrir une correction commerciale.',
                ]);
            }
            if ($order->procurements->contains(fn ($procurement) => ! in_array($procurement->status->value, ['cancelled', 'unavailable'], true))) {
                throw ValidationException::withMessages([
                    'order' => 'Résolvez les approvisionnements fournisseur liés avant de corriger cette commande.',
                ]);
            }
            if ($order->transferRequests->contains(fn ($request) => in_array($request->status, [TransferRequestStatus::Shipped, TransferRequestStatus::Received], true))) {
                throw ValidationException::withMessages([
                    'order' => 'Un transfert lié a déjà été expédié ou réceptionné. Sa correction nécessite un workflow logistique de retour.',
                ]);
            }
            if ($order->transferRequests->contains(fn ($request) => $request->status->isCancellable())) {
                $this->authorizeOrder($actor, $order, 'inventory.transfer_requests.manage');
            }

            $revision = new SalesOrderRevision;
            $revision->organization_id = $order->organization_id;
            $revision->store_id = $order->store_id;
            $revision->sales_order_id = $order->getKey();
            $revision->revision_number = ((int) $order->revisions()->max('revision_number')) + 1;
            $revision->status = 'in_progress';
            $revision->reason = $reason;
            $revision->before_snapshot = $this->snapshots->make($order);
            $revision->initiated_by_user_id = $actor->getKey();
            $revision->initiated_at = now();
            $revision->save();

            foreach ($order->lines as $line) {
                foreach ($line->allocations as $allocation) {
                    $reservation = $allocation->inventoryReservation;
                    if ($reservation && $reservation->status === InventoryReservationStatus::Active) {
                        $this->reservations->release($actor, $order->organization, $reservation);
                    }
                    $allocation->inventory_reservation_id = null;
                    $allocation->save();
                }
            }

            foreach ($order->transferRequests->whereIn('status', [TransferRequestStatus::Requested, TransferRequestStatus::Preparing]) as $request) {
                $this->transferRequests->execute($actor, $request, 'Correction commerciale de la commande', orderPortionOnly: true);
            }

            $order->current_revision_id = $revision->getKey();
            $order->status = SalesOrderStatus::Draft;
            $order->confirmed_at = null;
            $order->confirmed_by_user_id = null;
            $order->save();

            $this->audit->record('sales_order.correction_started', $actor, $order->organization, $order->store, $order, newValues: [
                'order_number' => $order->order_number,
                'revision_id' => $revision->getKey(),
                'revision_number' => $revision->revision_number,
                'reason' => $reason,
            ]);

            return $revision;
        });
    }
}
