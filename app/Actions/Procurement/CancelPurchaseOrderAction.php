<?php

namespace App\Actions\Procurement;

use App\Actions\Concerns\MatchesActorTenant;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\PurchaseOrder;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a supplier purchase order that was placed by mistake or refused by the
 * supplier. Its requirements return to SUPPLIER_CONFIRMED (to be ordered again);
 * refused once any of them has been received. Idempotent.
 */
class CancelPurchaseOrderAction
{
    use MatchesActorTenant;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, PurchaseOrder $purchaseOrder, string $reason): PurchaseOrder
    {
        $purchaseOrder->loadMissing(['organization', 'store']);
        abort_unless(
            $this->actorTenantMatches($actor, $purchaseOrder->organization, $purchaseOrder->store)
            && $actor->organizationMemberships()->where('organization_id', $purchaseOrder->organization_id)->where('status', 'active')->exists()
            && $actor->storeMemberships()->where('store_id', $purchaseOrder->store_id)->exists()
            && $actor->hasPermission($purchaseOrder->organization, 'procurement.manage'),
            403
        );

        return DB::transaction(function () use ($actor, $purchaseOrder, $reason) {
            $purchaseOrder = PurchaseOrder::query()->where('organization_id', $purchaseOrder->organization_id)
                ->whereKey($purchaseOrder->getKey())->lockForUpdate()->with(['organization', 'store', 'lines'])->firstOrFail();
            if ($purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
                return $purchaseOrder;
            }

            $requirements = SalesOrderProcurement::query()->where('organization_id', $purchaseOrder->organization_id)
                ->whereKey($purchaseOrder->lines->pluck('sales_order_procurement_id')->unique()->all())
                ->lockForUpdate()->get();
            if ($received = $requirements->first(fn ($r) => in_array($r->status, [SupplierProcurementStatus::Received, SupplierProcurementStatus::Completed], true))) {
                throw ValidationException::withMessages([
                    'purchase_order' => "Le besoin {$received->procurement_number} est déjà réceptionné : la commande fournisseur ne peut plus être annulée.",
                ]);
            }

            $old = $purchaseOrder->status;
            $purchaseOrder->status = PurchaseOrderStatus::Cancelled;
            $purchaseOrder->cancelled_at = now();
            $purchaseOrder->cancelled_by_user_id = $actor->getKey();
            $purchaseOrder->cancellation_reason = $reason;
            $purchaseOrder->save();

            foreach ($requirements as $requirement) {
                if ($requirement->status !== SupplierProcurementStatus::Ordered) {
                    continue;
                }
                $requirement->status = SupplierProcurementStatus::SupplierConfirmed;
                $requirement->ordered_at = null;
                $requirement->ordered_by_user_id = null;
                $requirement->save();
                $this->audit->record('procurement.order_reverted', $actor, $purchaseOrder->organization, $purchaseOrder->store, $requirement,
                    oldValues: ['status' => SupplierProcurementStatus::Ordered->value],
                    newValues: ['procurement_number' => $requirement->procurement_number, 'status' => SupplierProcurementStatus::SupplierConfirmed->value,
                        'purchase_order_number' => $purchaseOrder->purchase_order_number]);
            }

            $this->audit->record('purchase_order.cancelled', $actor, $purchaseOrder->organization, $purchaseOrder->store, $purchaseOrder,
                oldValues: ['status' => $old->value],
                newValues: ['purchase_order_number' => $purchaseOrder->purchase_order_number, 'status' => PurchaseOrderStatus::Cancelled->value, 'reason' => $reason]);

            return $purchaseOrder;
        });
    }
}
