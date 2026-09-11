<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Enums\TransferRequestStatus;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** requested → preparing. Logistics only — no inventory effect. */
class PrepareTransferRequestAction
{
    use AuthorizesInventoryAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, TransferRequest $request): TransferRequest
    {
        $this->authorizeInventory($actor, $request->organization, 'inventory.transfer_requests.manage');

        return DB::transaction(function () use ($actor, $request) {
            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()->with('organization')->firstOrFail();

            if ($request->status !== TransferRequestStatus::Requested) {
                throw ValidationException::withMessages(['status' => 'Seule une demande « demandée » peut être préparée.']);
            }

            $request->status = TransferRequestStatus::Preparing;
            $request->prepared_by_user_id = $actor->getKey();
            $request->prepared_at = now();
            $request->save();

            $this->audit->record('transfer_request.prepared', $actor, $request->organization, auditable: $request, newValues: [
                'request_number' => $request->request_number,
                'source_warehouse_id' => $request->source_warehouse_id,
                'destination_warehouse_id' => $request->destination_warehouse_id,
            ]);

            return $request;
        });
    }
}
