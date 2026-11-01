<?php

namespace App\Actions\Pos;

use App\Actions\Pos\Concerns\MutatesPosCart;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Procurement\SupplierAvailabilityRequestRules;
use Illuminate\Support\Facades\DB;

/** Withdraw one POS cart supplier availability request (never deleted). */
class CancelCartSupplierRequestAction
{
    use MutatesPosCart;

    public function __construct(
        private readonly SupplierAvailabilityRequestRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, SalesOrder $cart, QuotationSupplierAvailabilityRequest $request): QuotationSupplierAvailabilityRequest
    {
        // Either the cart editor or the supplier owner may withdraw a request.
        $permission = $actor->hasPermission($cart->organization_id, 'sales_orders.update') ? 'sales_orders.update' : 'procurement.manage';
        $this->authorizeCart($actor, $cart, [$permission]);

        return DB::transaction(function () use ($actor, $cart, $request) {
            $cart = $this->lockCart($cart);
            $request = QuotationSupplierAvailabilityRequest::query()
                ->where('organization_id', $cart->organization_id)
                ->where('sales_order_id', $cart->getKey())
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $this->rules->cancel($actor, $request);

            $this->audit->record('pos_cart_supplier_availability.cancelled', $actor, $cart->organization, $cart->store, $request, oldValues: [
                'status' => $previous,
            ], newValues: [
                'sales_order_id' => $cart->getKey(),
                'sales_order_line_id' => $request->sales_order_line_id,
                'supplier_id' => $request->supplier_id,
                'status' => $request->status->value,
            ]);

            return $request;
        });
    }
}
