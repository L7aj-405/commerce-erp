<?php

namespace App\Actions\Pos;

use App\Actions\Pos\Concerns\MutatesPosCart;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Procurement\SupplierAvailabilityRequestRules;
use Illuminate\Support\Facades\DB;

/**
 * Record what the supplier answered for one POS cart availability request.
 * Same rule as the Devis: the status is derived from the confirmed quantity
 * (full → available, some → partial, none → unavailable). A confirmation is
 * evidence only — never stock, a reservation or a purchase order.
 */
class RecordCartSupplierAvailabilityAction
{
    use MutatesPosCart;

    public function __construct(
        private readonly SupplierAvailabilityRequestRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{outcome: string, confirmed_quantity?: string|int|float|null, note?: string|null} $data */
    public function execute(User $actor, SalesOrder $cart, QuotationSupplierAvailabilityRequest $request, array $data): QuotationSupplierAvailabilityRequest
    {
        $this->authorizeCart($actor, $cart, ['procurement.manage']);

        return DB::transaction(function () use ($actor, $cart, $request, $data) {
            $cart = $this->lockCart($cart);
            $request = QuotationSupplierAvailabilityRequest::query()
                ->where('organization_id', $cart->organization_id)
                ->where('sales_order_id', $cart->getKey())
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $this->rules->recordOutcome($actor, $request, $data);

            $this->audit->record('pos_cart_supplier_availability.recorded', $actor, $cart->organization, $cart->store, $request, oldValues: [
                'status' => $previous,
            ], newValues: [
                'sales_order_id' => $cart->getKey(),
                'order_number' => $cart->order_number,
                'sales_order_line_id' => $request->sales_order_line_id,
                'supplier_id' => $request->supplier_id,
                'status' => $request->status->value,
                'requested_quantity' => $request->requested_quantity,
                'confirmed_quantity' => (string) $request->confirmed_quantity,
            ]);

            return $request;
        });
    }
}
