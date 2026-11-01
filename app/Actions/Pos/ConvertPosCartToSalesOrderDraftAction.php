<?php

namespace App\Actions\Pos;

use App\Actions\Pos\Concerns\MutatesPosCart;
use App\Enums\QuotationSupplierAvailabilityStatus;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pos\PosCartCommercialTerms;
use App\Services\Pos\PosCartSourcing;
use App\Services\ProcurementNumberGenerator;
use App\Services\SalespersonEligibilityService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Créer la commande" — hand a POS cart whose shortages are all covered (current
 * stock or full supplier confirmation) over to the regular Sales Order workflow
 * as a Sales Order DRAFT. The cart already IS a draft Sales Order, so its lines,
 * quantities, prices, discounts, customer and notes are kept as they are; the
 * cart-level terms (global discount, delivery fee) become real lines.
 *
 * The supplier evidence is carried forward, not re-asked: every confirmed
 * answer covering a cart shortage becomes ONE procurement requirement
 * (SUPPLIER_CONFIRMED) on the same Sales Order line, linked to its evidence
 * (`source_supplier_request_id`, unique). That is the existing Approvisionnement
 * requirement: the purchase order is created from it later, explicitly, and the
 * order can only be confirmed once the goods have physically been received
 * (SalesOrderProcurementPlanner). Nothing is ordered, reserved, paid, invoiced
 * or moved here.
 *
 * Retry-safe: under the cart row lock a converted cart is refused, so a second
 * submit can never derive a second set of requirements.
 */
class ConvertPosCartToSalesOrderDraftAction
{
    use MutatesPosCart;

    public function __construct(
        private readonly PosCartSourcing $sourcing,
        private readonly PosCartCommercialTerms $commercialTerms,
        private readonly ProcurementNumberGenerator $numbers,
        private readonly SalespersonEligibilityService $salespersons,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, SalesOrder $cart): SalesOrder
    {
        $this->authorizeCart($actor, $cart, ['pos.access', 'sales_orders.update']);

        return DB::transaction(function () use ($actor, $cart) {
            $cart = $this->lockCart($cart);
            $cart->loadMissing(['organization', 'store']);
            if (! $cart->lines()->exists()) {
                throw ValidationException::withMessages(['order' => 'Ajoutez au moins un produit avant de créer la commande.']);
            }

            $evaluation = $this->sourcing->evaluate($cart, lockRequests: true);
            if ($evaluation['summary']['shortage_line_count'] === 0) {
                throw ValidationException::withMessages(['order' => 'Le stock couvre tout le panier : finalisez la vente au POS.']);
            }
            $unresolved = array_filter($evaluation['lines'], fn (array $line) => $line['requires_sourcing'] && ! $line['resolved']);
            if ($unresolved !== []) {
                throw ValidationException::withMessages(['order' => array_values(array_map(
                    fn (array $line) => "{$line['article']} : il reste {$line['remaining_quantity']} à sourcer (manquant {$line['shortage']}, confirmé {$line['covered_quantity']}).",
                    $unresolved,
                ))]);
            }

            $this->commercialTerms->apply($actor, $cart);

            $created = [];
            foreach ($evaluation['lines'] as $lineId => $line) {
                if (! $line['requires_sourcing']) {
                    continue;
                }
                $created = [...$created, ...$this->deriveRequirements($actor, $cart, (int) $lineId, $line['shortage'])];
            }

            // The employee handing the cart over owns the order, as at checkout.
            $salesperson = $this->salespersons->resolve($cart->organization, $actor->getKey());
            if ($salesperson) {
                $cart->salesperson_id = $salesperson->getKey();
                $cart->salesperson_name_snapshot = $salesperson->name;
            }
            $cart->pos_held_at = null;
            $cart->pos_converted_at = now();
            $cart->save();

            $this->audit->record('sales_order.pos_cart_converted', $actor, $cart->organization, $cart->store, $cart, newValues: [
                'order_number' => $cart->order_number,
                'status' => $cart->status->value,
                'procurement_requirements' => array_map(fn (SalesOrderProcurement $p) => [
                    'procurement_number' => $p->procurement_number,
                    'sales_order_line_id' => $p->sales_order_line_id,
                    'supplier_id' => $p->supplier_id,
                    'quantity' => $p->quantity,
                    'source_supplier_request_id' => $p->source_supplier_request_id,
                ], $created),
            ]);

            return $cart->fresh(['lines', 'procurements']);
        });
    }

    /**
     * One SUPPLIER_CONFIRMED requirement per supplier answer, in answer order,
     * until the line's current shortage is covered.
     *
     * @return list<SalesOrderProcurement>
     */
    private function deriveRequirements(User $actor, SalesOrder $cart, int $lineId, string $shortage): array
    {
        $line = $cart->lines()->whereKey($lineId)->firstOrFail();
        $evidence = QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $cart->organization_id)
            ->where('sales_order_id', $cart->getKey())
            ->where('sales_order_line_id', $lineId)
            ->whereIn('status', [QuotationSupplierAvailabilityStatus::Available->value, QuotationSupplierAvailabilityStatus::Partial->value])
            ->whereNotNull('supplier_id')
            ->whereDoesntHave('derivedProcurement')
            ->with('supplier:id,name,active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (QuotationSupplierAvailabilityRequest $r) => $r->matchesLineArticle($line) && $r->supplier?->active);

        $remaining = Decimal::normalize($shortage);
        $created = [];
        foreach ($evidence as $request) {
            if (Decimal::compare($remaining, '0') <= 0) {
                break;
            }
            $confirmed = Decimal::normalize((string) $request->confirmed_quantity);
            $quantity = Decimal::compare($confirmed, $remaining) <= 0 ? $confirmed : $remaining;
            if (Decimal::compare($quantity, '0') <= 0) {
                continue;
            }

            $procurement = new SalesOrderProcurement;
            $procurement->organization_id = $cart->organization_id;
            $procurement->store_id = $cart->store_id;
            $procurement->procurement_number = $this->numbers->next($cart->organization);
            $procurement->supplier_id = $request->supplier_id;
            $procurement->sales_order_id = $cart->getKey();
            $procurement->sales_order_line_id = $line->getKey();
            $procurement->product_variant_id = $line->product_variant_id;
            $procurement->source_supplier_request_id = $request->getKey();
            $procurement->quantity = $quantity;
            $procurement->status = SupplierProcurementStatus::SupplierConfirmed;
            $procurement->supplier_availability_status = SupplierAvailabilityStatus::ConfirmedAvailable;
            $procurement->notes = "Disponibilité confirmée depuis le panier POS {$cart->order_number}.";
            $procurement->created_by_user_id = $actor->getKey();
            $procurement->confirmed_by_user_id = $request->resolved_by_user_id;
            $procurement->save();
            $created[] = $procurement;
            $remaining = Decimal::subtract($remaining, $quantity);

            $this->audit->record('procurement.created', $actor, $cart->organization, $cart->store, $procurement, newValues: [
                'procurement_number' => $procurement->procurement_number,
                'origin' => 'pos_cart_sourcing',
                'status' => $procurement->status->value,
                'sales_order_id' => $cart->getKey(),
                'sales_order_number' => $cart->order_number,
                'sales_order_line_id' => $procurement->sales_order_line_id,
                'supplier_id' => $procurement->supplier_id,
                'supplier_name' => $request->supplier?->name,
                'product_variant_id' => $procurement->product_variant_id,
                'quantity' => $procurement->quantity,
                'source_supplier_request_id' => $request->getKey(),
                'source_supplier_request_status' => $request->status->value,
                'source_confirmed_quantity' => $confirmed,
            ]);
        }

        if (Decimal::compare($remaining, '0') > 0) {
            // The gate said covered, but an answering supplier became inactive.
            throw ValidationException::withMessages(['order' => "{$line->product_name} : la disponibilité confirmée ne couvre plus le manquant (fournisseur inactif). Demandez un autre fournisseur."]);
        }

        return $created;
    }
}
