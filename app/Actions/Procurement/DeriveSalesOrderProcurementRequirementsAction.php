<?php

namespace App\Actions\Procurement;

use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use App\Services\ProcurementNumberGenerator;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Turn the supplier availability confirmed on the accepted quotation into
 * explicit procurement requirements of the Sales Order it was converted to:
 * one SUPPLIER_CONFIRMED requirement per line whose CURRENT shortage is covered
 * by a confirmation of one active supplier (SalesOrderProcurementPlanner decides).
 * The quotation answer is only the evidence — it is linked, never modified, and
 * it is never a purchase order. Nothing is ordered here.
 *
 * Idempotent: a line that already has a requirement is never derived again.
 * Runs inside the caller's transaction, under its Sales Order row lock and its
 * authorization: quotation conversion (the requirement is a consequence of that
 * act, so no separate procurement permission applies) or "Créer la commande
 * fournisseur" on an order converted before requirements existed
 * (procurement.manage). Sales Order confirmation never derives.
 */
class DeriveSalesOrderProcurementRequirementsAction
{
    public function __construct(
        private readonly SalesOrderProcurementPlanner $planner,
        private readonly ProcurementNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<SalesOrderProcurement> */
    public function execute(User $actor, SalesOrder $order): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Procurement requirements are derived inside the caller transaction.');
        }

        $order->loadMissing(['organization', 'store']);
        $created = [];
        foreach ($this->planner->derivable($order) as $candidate) {
            $request = $candidate['request'];

            $procurement = new SalesOrderProcurement;
            $procurement->organization_id = $order->organization_id;
            $procurement->store_id = $order->store_id;
            $procurement->procurement_number = $this->numbers->next($order->organization);
            $procurement->supplier_id = $request->supplier_id;
            $procurement->sales_order_id = $order->getKey();
            $procurement->sales_order_line_id = $candidate['line']->getKey();
            $procurement->product_variant_id = $candidate['line']->product_variant_id;
            $procurement->source_quotation_id = $candidate['quotation']->getKey();
            $procurement->source_quotation_line_id = $candidate['quotation_line']->getKey();
            $procurement->source_supplier_request_id = $request->getKey();
            $procurement->quantity = $candidate['quantity'];
            $procurement->status = SupplierProcurementStatus::SupplierConfirmed;
            $procurement->supplier_availability_status = SupplierAvailabilityStatus::ConfirmedAvailable;
            $procurement->notes = "Disponibilité confirmée sur le devis {$candidate['quotation']->quotation_number}.";
            $procurement->created_by_user_id = $actor->getKey();
            $procurement->confirmed_by_user_id = $request->resolved_by_user_id;
            $procurement->save();
            $created[] = $procurement;

            $this->audit->record('procurement.created', $actor, $order->organization, $order->store, $procurement, newValues: [
                'procurement_number' => $procurement->procurement_number,
                'origin' => 'quotation_sourcing',
                'status' => $procurement->status->value,
                'sales_order_id' => $order->getKey(),
                'sales_order_number' => $order->order_number,
                'sales_order_line_id' => $procurement->sales_order_line_id,
                'supplier_id' => $procurement->supplier_id,
                'supplier_name' => $request->supplier?->name,
                'product_variant_id' => $procurement->product_variant_id,
                'quantity' => $procurement->quantity,
                'source_quotation_id' => $procurement->source_quotation_id,
                'source_quotation_number' => $candidate['quotation']->quotation_number,
                'source_quotation_line_id' => $procurement->source_quotation_line_id,
                'source_supplier_request_id' => $procurement->source_supplier_request_id,
                'source_supplier_request_status' => $request->status->value,
                'source_confirmed_quantity' => (string) $request->confirmed_quantity,
            ]);
        }

        return $created;
    }
}
