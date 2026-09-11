<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record what the supplier told the sales employee about THIS procurement
 * requirement. Confirming availability moves the row to SUPPLIER_CONFIRMED so its
 * quantity may count towards Sales Order confirmation coverage. Marking it
 * unavailable leaves the customer line under-covered (§23) until another supplier
 * confirms or company stock covers it.
 */
class RecordSupplierAvailabilityAction
{
    use AuthorizesProcurementAction;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrderProcurement $procurement, array $data): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $procurement->organization, 'procurement.manage');

        return DB::transaction(function () use ($actor, $procurement, $data) {
            $procurement = SalesOrderProcurement::query()
                ->where('organization_id', $procurement->organization_id)
                ->whereKey($procurement->getKey())->lockForUpdate()
                ->with(['organization', 'store', 'salesOrderLine', 'supplier'])
                ->firstOrFail();

            if (! $procurement->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'La disponibilité ne peut plus être modifiée une fois la commande fournisseur passée.',
                ]);
            }

            $availability = SupplierAvailabilityStatus::from($data['supplier_availability_status']);

            if (array_key_exists('quantity', $data) && $data['quantity'] !== null && $data['quantity'] !== '') {
                $quantity = Decimal::normalize((string) $data['quantity']);
                if (Decimal::compare($quantity, '0') <= 0) {
                    throw ValidationException::withMessages(['quantity' => 'La quantité doit être positive.']);
                }
                if ($procurement->salesOrderLine && Decimal::compare($quantity, (string) $procurement->salesOrderLine->quantity) > 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'La quantité confirmée ne peut pas dépasser la quantité de la ligne.',
                    ]);
                }
                $procurement->quantity = $quantity;
            }

            if (array_key_exists('supplier_reference', $data)) {
                $procurement->supplier_reference = $data['supplier_reference'] ?: null;
            }
            if (array_key_exists('expected_at', $data)) {
                $procurement->expected_at = $data['expected_at'] ?: null;
            }
            if (array_key_exists('notes', $data)) {
                $procurement->notes = $data['notes'] ?: null;
            }

            $procurement->supplier_availability_status = $availability;

            $event = 'procurement.availability_recorded';
            if ($availability === SupplierAvailabilityStatus::ConfirmedAvailable) {
                $procurement->status = SupplierProcurementStatus::SupplierConfirmed;
                $procurement->confirmed_by_user_id = $actor->getKey();
                $event = 'procurement.supplier_confirmed';
            } elseif ($availability === SupplierAvailabilityStatus::Unavailable) {
                $procurement->status = SupplierProcurementStatus::Unavailable;
                $event = 'procurement.supplier_unavailable';
            } else {
                $procurement->status = SupplierProcurementStatus::PendingSupplier;
                $procurement->confirmed_by_user_id = null;
            }

            $procurement->save();

            $this->audit->record($event, $actor, $procurement->organization, $procurement->store, $procurement, newValues: [
                'procurement_number' => $procurement->procurement_number,
                'sales_order_id' => $procurement->sales_order_id,
                'supplier_id' => $procurement->supplier_id,
                'supplier_name' => $procurement->supplier?->name,
                'supplier_availability_status' => $availability->value,
                'status' => $procurement->status->value,
                'quantity' => $procurement->quantity,
            ]);

            return $procurement;
        });
    }
}
