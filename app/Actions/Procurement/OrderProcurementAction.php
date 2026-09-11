<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Place the order with the supplier once availability is confirmed and the
 * customer Order has been accepted (confirmed). Records `ordered_at` /
 * `ordered_by`. No inventory movement yet — the goods have not arrived.
 * Idempotent: a double click on "Commander au fournisseur" returns the row
 * unchanged.
 */
class OrderProcurementAction
{
    use AuthorizesProcurementAction;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrderProcurement $procurement, array $data = []): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $procurement->organization, 'procurement.manage');

        return DB::transaction(function () use ($actor, $procurement, $data) {
            $procurement = SalesOrderProcurement::query()
                ->where('organization_id', $procurement->organization_id)
                ->whereKey($procurement->getKey())->lockForUpdate()
                ->with(['organization', 'store', 'supplier'])
                ->firstOrFail();

            if ($procurement->status === SupplierProcurementStatus::Ordered) {
                return $procurement; // idempotent
            }
            if ($procurement->status !== SupplierProcurementStatus::SupplierConfirmed) {
                throw ValidationException::withMessages([
                    'status' => 'La disponibilité fournisseur doit être confirmée avant de commander.',
                ]);
            }

            $order = SalesOrder::query()->where('organization_id', $procurement->organization_id)
                ->whereKey($procurement->sales_order_id)->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => 'Confirmez la commande client avant de commander au fournisseur.',
                ]);
            }

            if (! empty($data['supplier_reference'])) {
                $procurement->supplier_reference = $data['supplier_reference'];
            }
            if (! empty($data['expected_at'])) {
                $procurement->expected_at = $data['expected_at'];
            }

            $procurement->status = SupplierProcurementStatus::Ordered;
            $procurement->ordered_at = now();
            $procurement->ordered_by_user_id = $actor->getKey();
            $procurement->save();

            $this->audit->record('procurement.ordered', $actor, $procurement->organization, $procurement->store, $procurement, newValues: [
                'procurement_number' => $procurement->procurement_number,
                'sales_order_id' => $procurement->sales_order_id,
                'sales_order_number' => $order->order_number,
                'supplier_id' => $procurement->supplier_id,
                'supplier_name' => $procurement->supplier?->name,
                'supplier_reference' => $procurement->supplier_reference,
                'quantity' => $procurement->quantity,
                'ordered_at' => $procurement->ordered_at?->toIso8601String(),
            ]);

            return $procurement;
        });
    }
}
