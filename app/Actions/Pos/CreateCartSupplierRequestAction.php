<?php

namespace App\Actions\Pos;

use App\Actions\Pos\Concerns\MutatesPosCart;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pos\PosCartSourcing;
use App\Services\Procurement\SupplierAvailabilityRequestRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Demander au fournisseur" for one POS cart line that current stock no longer
 * covers. Records pre-sale supplier availability evidence only — the very same
 * record and rules as a Devis line (SupplierAvailabilityRequestRules). Sends
 * nothing, orders nothing, reserves nothing, moves no stock.
 */
class CreateCartSupplierRequestAction
{
    use MutatesPosCart;

    public function __construct(
        private readonly PosCartSourcing $sourcing,
        private readonly SupplierAvailabilityRequestRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{supplier_id: int|string, requested_quantity?: string|int|float|null, note?: string|null} $data */
    public function execute(User $actor, SalesOrder $cart, SalesOrderLine $line, array $data): QuotationSupplierAvailabilityRequest
    {
        $this->authorizeCart($actor, $cart, ['pos.access', 'sales_orders.update', 'suppliers.view']);

        return DB::transaction(function () use ($actor, $cart, $line, $data) {
            $cart = $this->lockCart($cart);
            $line = $this->cartLine($cart, $line);

            $evaluation = $this->sourcing->evaluate($cart, lockRequests: true)['lines'][$line->getKey()] ?? null;
            if ($evaluation === null || ! $evaluation['requires_sourcing']) {
                throw ValidationException::withMessages(['line' => 'Cette ligne est entièrement couverte par le stock interne.']);
            }

            $supplier = $this->rules->activeSupplier((int) $cart->organization_id, $data['supplier_id']);
            $quantity = $this->rules->requestedQuantity($data, $evaluation['shortage'], (string) $line->quantity);
            $this->rules->assertNoPendingDuplicate(
                QuotationSupplierAvailabilityRequest::query()
                    ->where('organization_id', $cart->organization_id)
                    ->where('sales_order_line_id', $line->getKey())
                    ->where('supplier_id', $supplier->getKey())
                    ->get(),
                $supplier,
                $line->product_variant_id,
                null,
            );

            $request = $this->rules->newPendingRequest($actor, [
                'organization_id' => $cart->organization_id,
                'store_id' => $cart->store_id,
                'sales_order_id' => $cart->getKey(),
                'sales_order_line_id' => $line->getKey(),
            ], $supplier, $line->product_variant_id, null, $quantity, $data['note'] ?? null);

            $this->audit->record('pos_cart_supplier_availability.requested', $actor, $cart->organization, $cart->store, $request, newValues: [
                'sales_order_id' => $cart->getKey(),
                'order_number' => $cart->order_number,
                'sales_order_line_id' => $line->getKey(),
                'supplier_id' => $supplier->getKey(),
                'requested_quantity' => $request->requested_quantity,
                'shortage' => $evaluation['shortage'],
            ]);

            return $request;
        });
    }
}
