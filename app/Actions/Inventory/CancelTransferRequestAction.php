<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\SalesOrderStatus;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\SalesOrder;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a not-yet-shipped transfer request. A shipped or received request is
 * never silently cancelled — that needs an operational return process.
 *
 * A cancellation reason is mandatory and the cancelled request is kept (never
 * deleted) so it stays in the history and can be restored by
 * {@see RestoreTransferRequestAction} if it was cancelled by mistake.
 *
 * Customer protection: a request still carrying ORDER_FULFILLMENT quantity for
 * a CONFIRMED customer order cannot be cancelled from the logistics side —
 * that would strand the customer's reserved stock at the source with nothing
 * on its way. The order itself must be cancelled or corrected (which calls
 * this action with `$orderPortionOnly`).
 *
 * `$orderPortionOnly` (used by SalesOrder cancellation) drops only the
 * ORDER_FULFILLMENT lines: any MINIMUM_REPLENISHMENT / MANUAL demand may still
 * be useful and the request stays alive (detached from the order) if such lines
 * remain.
 */
class CancelTransferRequestAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, TransferRequest $request, ?string $reason = null, bool $orderPortionOnly = false): TransferRequest
    {
        $this->authorizeInventory($actor, $request->organization, 'inventory.transfer_requests.manage');

        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif d’annulation est obligatoire.']);
        }

        return DB::transaction(function () use ($actor, $request, $reason, $orderPortionOnly) {
            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()->with(['organization', 'lines'])->firstOrFail();

            if ($request->status === TransferRequestStatus::Cancelled) {
                return $request; // repeated submit — already done
            }

            if (! $request->status->isCancellable()) {
                throw ValidationException::withMessages([
                    'status' => 'Une demande expédiée ou réceptionnée ne peut pas être annulée.',
                ]);
            }

            if (! $orderPortionOnly && $request->sales_order_id !== null
                && $request->lines->contains(fn ($line) => $line->reason === TransferRequestReason::OrderFulfillment)) {
                $order = SalesOrder::query()->where('organization_id', $request->organization_id)
                    ->whereKey($request->sales_order_id)->first(['id', 'order_number', 'status']);
                if ($order && $order->status === SalesOrderStatus::Confirmed) {
                    throw ValidationException::withMessages([
                        'reason' => "Cette demande achemine le stock réservé de la commande {$order->order_number}. Annulez ou corrigez la commande ; seules les lignes de réassort peuvent être retirées ici.",
                    ]);
                }
            }

            if ($orderPortionOnly) {
                $request->lines()->where('reason', TransferRequestReason::OrderFulfillment->value)->delete();
                $request->load('lines');

                if ($request->lines->isNotEmpty()) {
                    // Replenishment demand survives — keep the request, detach it
                    // from the cancelled order.
                    $request->sales_order_id = null;
                    $request->lock_version = (int) $request->lock_version + 1;
                    $request->save();
                    $this->audit->record('transfer_request.order_portion_cancelled', $actor, $request->organization, auditable: $request, newValues: [
                        'request_number' => $request->request_number,
                        'remaining_reasons' => $request->lines->pluck('reason')->map(fn ($r) => $r->value)->unique()->values()->all(),
                    ]);

                    return $request;
                }
            }

            $previousStatus = $request->status->value;
            $request->status = TransferRequestStatus::Cancelled;
            $request->cancelled_by_user_id = $actor->getKey();
            $request->cancelled_at = now();
            $request->cancellation_reason = $reason;
            $request->lock_version = (int) $request->lock_version + 1;
            $request->save();

            $this->audit->record('transfer_request.cancelled', $actor, $request->organization, auditable: $request, oldValues: [
                'status' => $previousStatus,
            ], newValues: [
                'request_number' => $request->request_number,
                'sales_order_id' => $request->sales_order_id,
                'reason' => $reason,
                'order_portion_only' => $orderPortionOnly,
                'lines' => $request->lines->map(fn ($line) => [
                    'product_variant_id' => (int) $line->product_variant_id,
                    'quantity' => $line->quantity,
                    'reason' => $line->reason->value,
                ])->all(),
            ]);

            return $request;
        });
    }
}
