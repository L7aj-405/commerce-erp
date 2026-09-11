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
 * preparing → shipped. Still logistics only — the ledger transfer happens on
 * receipt. A shipped request can no longer be cancelled without an explicit
 * operational reverse/return.
 *
 * The optional `$shipment` payload carries the chauffeur / vehicle details
 * entered on the "Expédier" confirmation, captured here as an immutable
 * snapshot alongside shipped_at / shipped_by.
 */
class ShipTransferRequestAction
{
    use AuthorizesInventoryAction;
    use SnapshotsTransferDriver;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $shipment */
    public function execute(User $actor, TransferRequest $request, array $shipment = []): TransferRequest
    {
        $this->authorizeInventory($actor, $request->organization, 'inventory.transfer_requests.manage');

        return DB::transaction(function () use ($actor, $request, $shipment) {
            $request = TransferRequest::query()->where('organization_id', $request->organization_id)
                ->whereKey($request->getKey())->lockForUpdate()->with('organization')->firstOrFail();

            if ($request->status !== TransferRequestStatus::Preparing) {
                throw ValidationException::withMessages(['status' => 'Seule une demande « en préparation » peut être expédiée.']);
            }

            $driverSnapshot = $this->applyDriverSnapshot($request, $shipment);

            $request->status = TransferRequestStatus::Shipped;
            $request->shipped_by_user_id = $actor->getKey();
            $request->shipped_at = now();
            $request->save();

            if ($driverSnapshot !== null) {
                $this->audit->record('transfer_request.driver_assigned', $actor, $request->organization, auditable: $request, newValues: [
                    'request_number' => $request->request_number,
                    ...$driverSnapshot,
                ]);
            }

            $this->audit->record('transfer_request.shipped', $actor, $request->organization, auditable: $request, newValues: [
                'request_number' => $request->request_number,
                'source_warehouse_id' => $request->source_warehouse_id,
                'destination_warehouse_id' => $request->destination_warehouse_id,
                'driver_name' => $request->driver_name,
                'vehicle' => $request->vehicle,
                'vehicle_registration' => $request->vehicle_registration,
            ]);

            return $request;
        });
    }
}
