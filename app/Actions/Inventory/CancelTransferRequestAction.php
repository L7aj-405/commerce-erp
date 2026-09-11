<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a not-yet-shipped transfer request. A shipped or received request is
 * never silently cancelled — that needs an operational return process.
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

        return DB::transaction(function () use ($actor, $request, $reason, $orderPortionOnly) {
            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()->with(['organization', 'lines'])->firstOrFail();

            if (! $request->status->isCancellable()) {
                throw ValidationException::withMessages([
                    'status' => 'Une demande expédiée ou réceptionnée ne peut pas être annulée.',
                ]);
            }

            if ($orderPortionOnly) {
                $request->lines()->where('reason', TransferRequestReason::OrderFulfillment->value)->delete();
                $request->load('lines');

                if ($request->lines->isNotEmpty()) {
                    // Replenishment demand survives — keep the request, detach it
                    // from the cancelled order.
                    $request->sales_order_id = null;
                    $request->save();
                    $this->audit->record('transfer_request.order_portion_cancelled', $actor, $request->organization, auditable: $request, newValues: [
                        'request_number' => $request->request_number,
                        'remaining_reasons' => $request->lines->pluck('reason')->map(fn ($r) => $r->value)->unique()->values()->all(),
                    ]);

                    return $request;
                }
            }

            $request->status = TransferRequestStatus::Cancelled;
            $request->cancelled_by_user_id = $actor->getKey();
            $request->cancelled_at = now();
            $request->cancellation_reason = $reason;
            $request->save();

            $this->audit->record('transfer_request.cancelled', $actor, $request->organization, auditable: $request, newValues: [
                'request_number' => $request->request_number,
                'sales_order_id' => $request->sales_order_id,
                'reason' => $reason,
                'order_portion_only' => $orderPortionOnly,
            ]);

            return $request;
        });
    }
}
