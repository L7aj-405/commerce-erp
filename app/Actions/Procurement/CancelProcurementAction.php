<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\SupplierProcurementStatus;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a single procurement while its customer Order stays alive (§22).
 *
 *   - not yet ordered  → plain cancel, no inventory effect;
 *   - already ordered   → refused unless the caller explicitly acknowledges the
 *                         open supplier order (an operational decision, not a
 *                         silent disappearance);
 *   - already received  → refused here — the goods are real company stock
 *                         earmarked for the Order; resolve it through the Order
 *                         (or a future returns workflow). Inventory is never
 *                         destroyed.
 */
class CancelProcurementAction
{
    use AuthorizesProcurementAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, SalesOrderProcurement $procurement, ?string $reason = null, bool $acknowledgeOrdered = false): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $procurement->organization, 'procurement.manage');

        return DB::transaction(function () use ($actor, $procurement, $reason, $acknowledgeOrdered) {
            $procurement = SalesOrderProcurement::query()
                ->where('organization_id', $procurement->organization_id)
                ->whereKey($procurement->getKey())->lockForUpdate()
                ->with(['organization', 'store'])
                ->firstOrFail();

            if ($procurement->status === SupplierProcurementStatus::Cancelled) {
                return $procurement; // idempotent
            }
            if ($procurement->status === SupplierProcurementStatus::Completed) {
                throw ValidationException::withMessages(['status' => 'Approvisionnement déjà clôturé.']);
            }
            if ($procurement->status === SupplierProcurementStatus::Received) {
                throw ValidationException::withMessages([
                    'status' => 'Approvisionnement déjà réceptionné : la marchandise est réservée à la commande. Gérez-le via la commande client.',
                ]);
            }
            if ($procurement->status === SupplierProcurementStatus::Ordered && ! $acknowledgeOrdered) {
                throw ValidationException::withMessages([
                    'status' => "Approvisionnement {$procurement->procurement_number} déjà commandé au fournisseur. Une décision opérationnelle explicite est requise.",
                ]);
            }

            $oldStatus = $procurement->status;
            $procurement->status = SupplierProcurementStatus::Cancelled;
            $procurement->cancellation_reason = $reason;
            $procurement->cancelled_by_user_id = $actor->getKey();
            $procurement->save();

            $this->audit->record('procurement.cancelled', $actor, $procurement->organization, $procurement->store, $procurement, oldValues: [
                'status' => $oldStatus->value,
            ], newValues: [
                'procurement_number' => $procurement->procurement_number,
                'sales_order_id' => $procurement->sales_order_id,
                'supplier_id' => $procurement->supplier_id,
                'quantity' => $procurement->quantity,
                'reason' => $reason,
                'acknowledged_ordered' => $oldStatus === SupplierProcurementStatus::Ordered,
            ]);

            return $procurement;
        });
    }
}
