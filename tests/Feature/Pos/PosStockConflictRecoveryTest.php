<?php

namespace Tests\Feature\Pos;

use App\Actions\Pos\CancelCartSupplierRequestAction;
use App\Actions\Pos\ConvertPosCartToSalesOrderDraftAction;
use App\Actions\Pos\CreateCartSupplierRequestAction;
use App\Actions\Pos\CreatePosSaleAction;
use App\Actions\Pos\RecordCartSupplierAvailabilityAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\RemoveSalesOrderLineAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SupplierProcurementStatus;
use App\Exceptions\Pos\PosStockConflictException;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\PosCartSourcing;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

/**
 * F2 — stock conflict recovery & supplier sourcing from the POS cart.
 */
class PosStockConflictRecoveryTest extends PosTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $showroom;

    private ProductVariant $variant;

    private Supplier $mifa;

    private Supplier $casa;

    private FinancialAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->showroom = $this->createWarehouse($this->organization, 'Showroom', 'SHW');
        $this->variant = $this->createProduct($this->organization, 'Fauteuil Oslo', 'OSLO', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->mifa = $this->supplier('Mifa');
        $this->casa = $this->supplier('Casa Sin');
        $this->cash = $this->createPosAccount($this->organization, 'cash', 'POS-CASH');
    }

    // --- §10 concurrency: two agents, the last unit -------------------------

    public function test_two_agents_selling_the_last_unit_only_the_first_succeeds_and_the_loser_has_no_side_effects(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '1.0000');
        $cartA = $this->cart('1.0000');
        $cartB = $this->cart('1.0000');

        $completed = app(CreatePosSaleAction::class)->execute($this->owner, $this->organization, $this->store, $this->posDraftCheckoutPayload($cartA, [
            $this->posPayment($this->cash, '100.0000'),
        ]));
        $this->assertSame('confirmed', $completed->status->value);

        $before = $this->sideEffects();
        $response = $this->actingAs($this->owner)->postJson(route('pos.sales.store'), $this->posDraftCheckoutPayload($cartB->fresh(), [
            $this->posPayment($this->cash, '100.0000'),
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('order_id')
            ->assertJsonPath('stock_conflict.message', PosStockConflictException::MESSAGE)
            ->assertJsonPath('stock_conflict.lines.0.line_id', $cartB->lines->first()->id)
            ->assertJsonPath('stock_conflict.lines.0.product', 'Fauteuil Oslo')
            ->assertJsonPath('stock_conflict.lines.0.requested', '1.0000')
            ->assertJsonPath('stock_conflict.lines.0.available', '0.0000')
            ->assertJsonPath('stock_conflict.lines.0.missing', '1.0000');

        // No invalid sale, no payment, no stock consumed, no reservation, nothing partial.
        $this->assertSame($before, $this->sideEffects());
        $this->assertSame('draft', $cartB->fresh()->status->value);
        $this->assertNull($cartB->fresh()->client_operation_id);
        $this->assertSame(0, Payment::query()->where('client_operation_id', '!=', $completed->client_operation_id)->count());
        $this->assertSame('0.0000', $this->balance($this->organization, $this->showroom, $this->variant)->on_hand);
        $this->assertSame('0.0000', $this->balance($this->organization, $this->showroom, $this->variant)->reserved);
    }

    public function test_every_short_line_is_reported_and_a_still_covered_line_is_not(): void
    {
        $other = $this->createProduct($this->organization, 'Table Nara', 'NARA', ['default_sale_price' => '50.0000'])->variants->first();
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '3.0000');
        $this->openStock($this->owner, $this->organization, $this->showroom, $other, '5.0000');
        $cart = $this->cart('3.0000');
        $this->line($cart, $other, '2.0000');
        $this->sellElsewhere($this->variant, '2.0000');

        try {
            app(CreatePosSaleAction::class)->execute($this->owner, $this->organization, $this->store, $this->posDraftCheckoutPayload($cart->fresh(), []));
            $this->fail('The stock conflict must reject the checkout.');
        } catch (PosStockConflictException $conflict) {
            $this->assertCount(1, $conflict->lines);
            $this->assertSame(['requested' => '3.0000', 'available' => '1.0000', 'missing' => '2.0000'], array_intersect_key($conflict->lines[0], array_flip(['requested', 'available', 'missing'])));
        }
        $this->assertSame('draft', $cart->fresh()->status->value);
    }

    public function test_finalization_replans_a_stale_warehouse_allocation_when_the_company_still_covers_it(): void
    {
        $depot = $this->createWarehouse($this->organization, 'Dépôt', 'DEP');
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '1.0000');
        $this->openStock($this->owner, $this->organization, $depot, $this->variant, '1.0000');
        $cart = $this->cart('1.0000'); // allocated to the showroom at entry
        $this->sellElsewhere($this->variant, '1.0000', $this->showroom);

        $completed = app(CreatePosSaleAction::class)->execute($this->owner, $this->organization, $this->store, $this->posDraftCheckoutPayload($cart->fresh(), [], ['fulfillment_mode' => 'delivery']));

        $this->assertSame('confirmed', $completed->status->value);
        $this->assertSame([$depot->id], $completed->lines()->first()->allocations()->pluck('warehouse_id')->all());
    }

    // --- §2 recovery: the same cart comes back ------------------------------

    public function test_the_operator_returns_to_the_same_cart_with_everything_preserved_and_shortages_to_source(): void
    {
        $customer = $this->createCustomer($this->organization, 'Client Atlas');
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '2.0000');
        $cart = $this->cart('2.0000', $customer, ['discount_type' => 'percentage', 'discount_value' => '10.0000', 'unit_price_excl_tax' => '90.0000']);
        $this->sellElsewhere($this->variant, '2.0000');
        $line = $cart->lines->first();

        $this->actingAs($this->owner)->post(route('pos.sales.store'), $this->posDraftCheckoutPayload($cart, []))
            ->assertRedirect(route('pos.index'))
            ->assertSessionHasErrors('order_id')
            ->assertSessionHas('pos.stock_conflict.message', PosStockConflictException::MESSAGE)
            ->assertSessionHas('pos.stock_conflict.lines.0.missing', '2.0000');

        $this->actingAs($this->owner)
            ->withSession(['pos.stock_conflict' => ['message' => PosStockConflictException::MESSAGE, 'lines' => []]])
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stockConflict.message', PosStockConflictException::MESSAGE)
                ->where('activeSale.id', $cart->id)
                ->where('activeSale.customer.id', $customer->id)
                ->where('activeSale.lines.0.id', $line->id)
                ->where('activeSale.lines.0.quantity', '2.0000')
                ->where('activeSale.lines.0.unit_price_excl_tax', $line->unit_price_excl_tax)
                ->where('activeSale.lines.0.discount_type', 'percentage')
                ->where('activeSale.lines.0.discount_value', '10.0000')
                ->where('activeSale.lines.0.sourcing.state', 'to_source')
                ->where('activeSale.lines.0.sourcing.shortage', '2.0000')
                ->where('activeSale.sourcing_summary.can_convert', false));
    }

    // --- §4 / §5 supplier sourcing from the cart ------------------------------

    public function test_cart_supplier_requests_reuse_the_quotation_rules_and_states(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '2.0000');
        $cart = $this->cart('5.0000');
        $line = $cart->lines->first();

        $request = $this->requestSupplier($cart, $line, $this->mifa);
        $this->assertSame('3.0000', $request->requested_quantity); // defaults to the missing quantity
        $this->assertNull($request->quotation_id);
        $this->assertSame('pending', $this->sourcing($cart, $line)['state']);

        // Same duplicate rule as the Devis.
        try {
            $this->requestSupplier($cart, $line, $this->mifa);
            $this->fail('A second pending request to the same supplier must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supplier_id', $e->errors());
        }

        $this->record($cart, $request, 'unavailable');
        $this->assertSame('unavailable', $this->sourcing($cart, $line)['state']);
        $this->assertFalse(app(PosCartSourcing::class)->evaluate($cart)['summary']['can_convert']);
    }

    public function test_partial_confirmation_does_not_unblock_and_another_supplier_completes_the_shortage(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '2.0000');
        $cart = $this->cart('5.0000');
        $line = $cart->lines->first();

        $this->record($cart, $this->requestSupplier($cart, $line, $this->mifa), 'available', '2');
        $sourcing = $this->sourcing($cart, $line);
        $this->assertSame('partial', $sourcing['state']);
        $this->assertSame('1.0000', $sourcing['remaining_quantity']);
        $this->assertFalse($sourcing['resolved']);
        $this->assertConversionRefused($cart);

        $this->record($cart, $this->requestSupplier($cart, $line, $this->casa, '1'), 'available', '1');
        $sourcing = $this->sourcing($cart, $line);
        $this->assertTrue($sourcing['resolved']);
        $this->assertSame('available', $sourcing['state']);
        $this->assertTrue(app(PosCartSourcing::class)->evaluate($cart)['summary']['can_convert']);
    }

    public function test_a_cart_fully_covered_by_stock_is_finalized_not_converted(): void
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '5.0000');
        $cart = $this->cart('1.0000');

        $this->assertConversionRefused($cart);
        $this->expectException(ValidationException::class);
        $this->requestSupplier($cart, $cart->lines->first(), $this->mifa);
    }

    // --- §5 / §6 conversion with evidence carried forward -----------------------

    public function test_a_fully_sourced_cart_becomes_a_sales_order_draft_carrying_its_supplier_evidence(): void
    {
        [$cart, $line, $requests] = $this->fullySourcedCart();
        $before = $this->sideEffects();

        $this->actingAs($this->owner)->postJson(route('pos.drafts.convert', $cart))
            ->assertOk()
            ->assertJsonPath('sales_order.id', $cart->id)
            ->assertJsonPath('active_sale', null);

        $order = $cart->fresh();
        $this->assertSame('draft', $order->status->value);
        $this->assertNotNull($order->pos_converted_at);
        // Same lines, same prices: nothing was rebuilt.
        $this->assertSame([$line->id], $order->lines()->pluck('id')->all());

        $requirements = SalesOrderProcurement::query()->where('sales_order_id', $order->id)->orderBy('id')->get();
        $this->assertSame([[$this->mifa->id, '2.0000', $requests[0]->id], [$this->casa->id, '1.0000', $requests[1]->id]],
            $requirements->map(fn ($p) => [$p->supplier_id, $p->quantity, $p->source_supplier_request_id])->all());
        $this->assertTrue($requirements->every(fn ($p) => $p->status === SupplierProcurementStatus::SupplierConfirmed && $p->sales_order_line_id === $line->id));

        // Supplier confirmation is evidence: no stock, no reservation, no payment, no PO.
        $this->assertSame($before, $this->sideEffects());
        $this->assertSame(0, PurchaseOrderLine::query()->count());

        // The planner (Approvisionnement) shows it and confirmation waits for the goods.
        $plan = app(SalesOrderProcurementPlanner::class)->plan($order);
        $this->assertSame('procurement_required', $plan['lines'][0]['state']);
        $this->assertSame('cart', $plan['lines'][0]['availability']['source']);
        $this->assertTrue($plan['summary']['ready_for_ordering']);
        $this->assertTrue($plan['summary']['confirmation_waits_for_receipt']);
        $this->assertConfirmationBlocked($order);

        // Retry / double click: refused, never a second set of requirements.
        $this->actingAs($this->owner)->postJson(route('pos.drafts.convert', $cart))->assertNotFound();
        $this->assertConversionRefused($order);
        $this->assertSame(2, SalesOrderProcurement::query()->count());

        // The converted draft left the POS queues and can no longer be checked out there.
        $this->expectException(ValidationException::class);
        app(CreatePosSaleAction::class)->execute($this->owner, $this->organization, $this->store, $this->posDraftCheckoutPayload($order, []));
    }

    // --- §7 – §9 Approvisionnement → PO → receipt → confirmation --------------------

    public function test_the_converted_order_follows_the_existing_procurement_lifecycle_to_confirmation(): void
    {
        [$cart, $line] = $this->fullySourcedCart();
        app(ConvertPosCartToSalesOrderDraftAction::class)->execute($this->owner, $cart);
        $order = $cart->fresh();
        $requirements = SalesOrderProcurement::query()->where('sales_order_id', $order->id)->orderBy('id')->get();

        $poMifa = app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($this->owner, $this->organization, $this->mifa, [$requirements[0]->id]);
        $poCasa = app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($this->owner, $this->organization, $this->casa, [$requirements[1]->id]);
        $this->assertSame(2, PurchaseOrderLine::query()->count());
        // Ordered is not received: still no stock and still not confirmable.
        $this->assertSame('2.0000', $this->balance($this->organization, $this->showroom, $this->variant)->on_hand);
        $this->assertConfirmationBlocked($order);

        // A second purchase order for the same need is refused (no duplicate procurement).
        try {
            app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($this->owner, $this->organization, $this->mifa, [$requirements[0]->id]);
            $this->fail('An ordered requirement cannot be ordered twice.');
        } catch (ValidationException) {
        }

        foreach ([$poMifa, $poCasa] as $po) {
            $poLine = $po->lines()->sole();
            app(ReceiveProcurementAction::class)->execute($this->owner, SalesOrderProcurement::query()->findOrFail($poLine->sales_order_procurement_id), $this->showroom, [
                'quantity' => (string) $poLine->quantity, 'purchase_order_line_id' => $poLine->id,
            ]);
        }
        // Receipt adds the goods and holds them for this order.
        $balance = $this->balance($this->organization, $this->showroom, $this->variant);
        $this->assertSame('5.0000', $balance->on_hand);
        $this->assertSame('3.0000', $balance->reserved);
        $this->assertTrue(app(SalesOrderProcurementPlanner::class)->plan($order->fresh())['summary']['confirmation_ready']);

        $confirmed = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());
        $this->assertSame('confirmed', $confirmed->status->value);
        $this->assertSame('5.0000', $this->balance($this->organization, $this->showroom, $this->variant)->reserved);
        $this->assertSame(2, SalesOrderProcurement::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('5.0000', $line->fresh()->allocations->reduce(fn ($sum, $a) => bcadd($sum, (string) $a->quantity, 4), '0.0000'));

        // Reservation merge: the two supplier receipts (one per requirement) at the
        // same warehouse are consolidated into ONE active reservation of the line;
        // the other is released, never deleted; the total reserved is exact.
        $active = InventoryReservation::query()->where('status', 'active')->get();
        $this->assertCount(1, $active);
        $this->assertSame('5.0000', $active->first()->quantity);
        $this->assertSame(1, InventoryReservation::query()->where('status', 'released')->count());
        // Provenance kept: each receipt still names its own requirement, PO line and movement.
        $receipts = \App\Models\ProcurementReceipt::query()->orderBy('id')->get();
        $this->assertCount(2, $receipts);
        $this->assertSame($requirements->pluck('id')->all(), $receipts->pluck('sales_order_procurement_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($receipts->every(fn ($r) => (int) $r->inventory_reservation_id === (int) $active->first()->id && $r->purchase_order_line_id !== null && $r->inventory_movement_id !== null));
        $this->assertSame(2, InventoryMovement::query()->where('movement_type', 'supplier_receipt')->count());
    }

    // --- housekeeping -----------------------------------------------------------------

    public function test_cancelled_requests_stop_counting_and_a_removed_line_takes_its_evidence_with_it(): void
    {
        $cart = $this->cart('2.0000');
        $line = $cart->lines->first();
        $request = $this->requestSupplier($cart, $line, $this->mifa);
        app(CancelCartSupplierRequestAction::class)->execute($this->owner, $cart, $request);
        $this->assertSame('to_source', $this->sourcing($cart, $line)['state']);

        $this->requestSupplier($cart, $line, $this->mifa);
        app(RemoveSalesOrderLineAction::class)->execute($this->owner, $cart->fresh(), $line);
        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->count());
    }

    public function test_a_supplier_of_another_organization_cannot_be_asked(): void
    {
        $stranger = User::factory()->create();
        $otherOrganization = $this->createOrganization($stranger);
        $foreign = new Supplier;
        $foreign->organization_id = $otherOrganization->id;
        $foreign->name = 'Foreign';
        $foreign->active = true;
        $foreign->save();
        $cart = $this->cart('1.0000');

        $this->expectException(ValidationException::class);
        $this->requestSupplier($cart, $cart->lines->first(), $foreign);
    }

    public function test_the_pos_sourcing_endpoints_round_trip_through_the_cart(): void
    {
        $cart = $this->cart('1.0000');
        $line = $cart->lines->first();

        $this->actingAs($this->owner)->getJson(route('pos.drafts.sourcing.supplier-options', [$cart, $line]))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->owner)->postJson(route('pos.drafts.sourcing.store', [$cart, $line]), ['supplier_id' => $this->mifa->id])
            ->assertOk()->assertJsonPath('active_sale.lines.0.sourcing.state', 'pending');
        $request = QuotationSupplierAvailabilityRequest::query()->sole();
        $this->actingAs($this->owner)->patchJson(route('pos.drafts.sourcing.record', [$cart, $request]), ['outcome' => 'available'])
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.sourcing.state', 'available')
            ->assertJsonPath('active_sale.sourcing_summary.can_convert', true);
    }

    // --- helpers ------------------------------------------------------------------------

    /** @return array{SalesOrder, SalesOrderLine, list<QuotationSupplierAvailabilityRequest>} */
    private function fullySourcedCart(): array
    {
        $this->openStock($this->owner, $this->organization, $this->showroom, $this->variant, '2.0000');
        $cart = $this->cart('5.0000');
        $line = $cart->lines->first();
        $a = $this->record($cart, $this->requestSupplier($cart, $line, $this->mifa), 'available', '2');
        $b = $this->record($cart, $this->requestSupplier($cart, $line, $this->casa, '1'), 'available', '1');

        return [$cart->fresh(), $line, [$a, $b]];
    }

    private function cart(string $quantity, ?Customer $customer = null, array $line = []): SalesOrder
    {
        $cart = $this->createPosDraft($this->owner, $this->organization, $this->store, $this->showroom, $customer?->id);
        $this->line($cart, $this->variant, $quantity, $line);

        return $cart->fresh(['lines']);
    }

    private function line(SalesOrder $cart, ProductVariant $variant, string $quantity, array $overrides = []): SalesOrderLine
    {
        return app(SaveSalesOrderLineAction::class)->execute($this->owner, $cart, array_replace([
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(), 'warehouse_id' => $this->showroom->getKey(),
            'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $overrides));
    }

    /** Another, already confirmed sale takes company stock. */
    private function sellElsewhere(ProductVariant $variant, string $quantity, ?Warehouse $warehouse = null): void
    {
        $other = $this->createDraftOrder($this->owner, $this->organization, $this->store);
        app(SaveSalesOrderLineAction::class)->execute($this->owner, $other, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(), 'warehouse_id' => ($warehouse ?? $this->showroom)->getKey(),
            'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0.0000',
        ]);
        app(ConfirmSalesOrderAction::class)->execute($this->owner, $other->fresh());
    }

    private function requestSupplier(SalesOrder $cart, SalesOrderLine $line, Supplier $supplier, ?string $quantity = null): QuotationSupplierAvailabilityRequest
    {
        return app(CreateCartSupplierRequestAction::class)->execute($this->owner, $cart, $line, array_filter([
            'supplier_id' => $supplier->id, 'requested_quantity' => $quantity,
        ], fn ($value) => $value !== null));
    }

    private function record(SalesOrder $cart, QuotationSupplierAvailabilityRequest $request, string $outcome, ?string $confirmed = null): QuotationSupplierAvailabilityRequest
    {
        return app(RecordCartSupplierAvailabilityAction::class)->execute($this->owner, $cart, $request, array_filter([
            'outcome' => $outcome, 'confirmed_quantity' => $confirmed,
        ], fn ($value) => $value !== null));
    }

    /** @return array<string, mixed> */
    private function sourcing(SalesOrder $cart, SalesOrderLine $line): array
    {
        return app(PosCartSourcing::class)->evaluate($cart->fresh())['lines'][$line->id];
    }

    private function assertConversionRefused(SalesOrder $cart): void
    {
        try {
            app(ConvertPosCartToSalesOrderDraftAction::class)->execute($this->owner, $cart->fresh());
            $this->fail('The cart must not be converted.');
        } catch (ValidationException) {
        }
    }

    private function assertConfirmationBlocked(SalesOrder $order): void
    {
        try {
            app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());
            $this->fail('The order must wait for the supplier goods.');
        } catch (ValidationException) {
        }
        $this->assertSame('draft', $order->fresh()->status->value);
    }

    /** @return array<string, int|string> */
    private function sideEffects(): array
    {
        $balance = $this->balance($this->organization, $this->showroom, $this->variant);

        return [
            'confirmed_orders' => SalesOrder::query()->where('status', 'confirmed')->count(),
            'payments' => Payment::query()->count(),
            'reservations' => InventoryReservation::query()->count(),
            'movements' => InventoryMovement::query()->count(),
            'on_hand' => $balance->on_hand,
            'reserved' => $balance->reserved,
        ];
    }

    private function supplier(string $name): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $this->organization->id;
        $supplier->name = $name;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }
}
