<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\SalesOrderProcurement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replace the supplier on a procurement that has NOT yet been ordered (§23).
 * Availability resets to "à confirmer" — the new supplier has not confirmed
 * anything yet — so the customer Order is no longer covered by this row until the
 * new supplier is confirmed.
 */
class ChangeProcurementSupplierAction
{
    use AuthorizesProcurementAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, SalesOrderProcurement $procurement, Supplier $supplier): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $procurement->organization, 'procurement.manage');

        return DB::transaction(function () use ($actor, $procurement, $supplier) {
            $procurement = SalesOrderProcurement::query()
                ->where('organization_id', $procurement->organization_id)
                ->whereKey($procurement->getKey())->lockForUpdate()
                ->with(['organization', 'store'])
                ->firstOrFail();

            if (! $procurement->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'Le fournisseur ne peut plus être changé une fois la commande fournisseur passée.',
                ]);
            }

            abort_unless($supplier->organization_id === $procurement->organization_id, 404);
            if (! $supplier->active) {
                throw ValidationException::withMessages(['supplier_id' => 'Ce fournisseur est inactif.']);
            }

            $previousSupplierId = $procurement->supplier_id;
            $procurement->supplier_id = $supplier->getKey();
            $procurement->supplier_availability_status = SupplierAvailabilityStatus::PendingConfirmation;
            $procurement->status = SupplierProcurementStatus::PendingSupplier;
            $procurement->confirmed_by_user_id = null;
            $procurement->supplier_reference = null;
            $procurement->save();

            $this->audit->record('procurement.supplier_changed', $actor, $procurement->organization, $procurement->store, $procurement, oldValues: [
                'supplier_id' => $previousSupplierId,
            ], newValues: [
                'procurement_number' => $procurement->procurement_number,
                'sales_order_id' => $procurement->sales_order_id,
                'supplier_id' => $supplier->getKey(),
                'supplier_name' => $supplier->name,
            ]);

            return $procurement;
        });
    }
}
