<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\AuthorizesInventoryAction;
use App\Actions\Inventory\Concerns\SnapshotsTransferDriver;
use App\Enums\TransferRequestStatus;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record / update who transports the goods (chauffeur / livreur) and the
 * optional vehicle trace, independently of shipping. Pure operational metadata
 * — no inventory or lifecycle effect. Allowed while the request is preparing or
 * shipped (a name can still be corrected after departure); never on a received
 * or cancelled request.
 */
class AssignTransferRequestDriverAction
{
    use AuthorizesInventoryAction;
    use SnapshotsTransferDriver;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, TransferRequest $request, array $data): TransferRequest
    {
        $this->authorizeInventory($actor, $request->organization, 'inventory.transfer_requests.manage');

        return DB::transaction(function () use ($actor, $request, $data) {
            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()->with('organization')->firstOrFail();

            if (! in_array($request->status, [TransferRequestStatus::Preparing, TransferRequestStatus::Shipped], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Le chauffeur ne peut être renseigné que sur une demande en préparation ou expédiée.',
                ]);
            }

            $snapshot = $this->applyDriverSnapshot($request, $data);
            $request->save();

            if ($snapshot !== null) {
                $this->audit->record('transfer_request.driver_assigned', $actor, $request->organization, auditable: $request, newValues: [
                    'request_number' => $request->request_number,
                    ...$snapshot,
                ]);
            }

            return $request;
        });
    }
}
