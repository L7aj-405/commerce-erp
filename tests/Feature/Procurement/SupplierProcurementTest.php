<?php

namespace Tests\Feature\Procurement;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Procurement\CancelProcurementAction;
use App\Actions\Procurement\ChangeProcurementSupplierAction;
use App\Actions\Procurement\CreateProcurementAction;
use App\Actions\Procurement\OrderProcurementAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Actions\Procurement\RecordSupplierAvailabilityAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesTestCase;

class SupplierProcurementTest extends SalesTestCase
{
    /** @return array{User, Organization, Store, Warehouse, Warehouse, ProductVariant, Supplier} */
    private function context(string $showroomStock = '4.0000', string $depotStock = '0.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $showroom = $this->createWarehouse($organization, 'Showroom', 'SHW');
        $depot = $this->createWarehouse($organization, 'Dépôt', 'DEP');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Canapé', 'CAN', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        if ($showroomStock !== '0.0000') {
            $this->openStock($owner, $organization, $showroom, $variant, $showroomStock);
        }
        if ($depotStock !== '0.0000') {
            $this->openStock($owner, $organization, $depot, $variant, $depotStock);
        }
        $supplier = $this->makeSupplier($organization, 'Casa Sin');

        return [$owner, $organization, $store, $showroom, $depot, $variant, $supplier];
    }

    private function makeSupplier(Organization $organization, string $name, bool $active = true): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->getKey();
        $supplier->name = $name;
        $supplier->active = $active;
        $supplier->save();

        return $supplier;
    }

    /** @return array{SalesOrder, SalesOrderLine} */
    private function draftWithLine(User $owner, Organization $org, Store $store, ProductVariant $variant, Warehouse $warehouse, string $qty): array
    {
        $order = $this->createDraftOrder($owner, $org, $store, null);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => $qty]);

        return [$order->fresh(), $line->fresh()];
    }

    private function raise(User $owner, SalesOrder $order, SalesOrderLine $line, Supplier $supplier, string $qty): SalesOrderProcurement
    {
        return app(CreateProcurementAction::class)->execute($owner, $order->fresh(), $line->fresh(), $supplier, ['quantity' => $qty]);
    }

    private function confirmAvailability(User $owner, SalesOrderProcurement $p, string $status = 'confirmed_available'): SalesOrderProcurement
    {
        return app(RecordSupplierAvailabilityAction::class)->execute($owner, $p->fresh(), ['supplier_availability_status' => $status]);
    }

    // --- 1. company stock insufficient is detectable ----------------------

    public function test_a_line_can_be_flagged_as_exceeding_company_available_stock(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');

        $procurement = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $this->assertSame('6.0000', $procurement->quantity);
        $this->assertSame(SupplierProcurementStatus::PendingSupplier, $procurement->status);
        $this->assertSame(SupplierAvailabilityStatus::PendingConfirmation, $procurement->supplier_availability_status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.created', 'auditable_id' => $procurement->id]);
    }

    // --- 2. split sourcing ---------------------------------------------------

    public function test_split_sourcing_reserves_only_company_stock_and_procures_the_rest(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $procurement = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));

        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->assertSame('confirmed', $confirmed->status->value);
        $allocated = $line->fresh()->allocations()->sum('quantity');
        $this->assertSame(4.0, (float) $allocated, 'only the company portion is allocated');
        $reserved = InventoryBalance::query()->where('warehouse_id', $showroom->id)->where('product_variant_id', $variant->id)->value('reserved');
        $this->assertSame(4.0, (float) $reserved, 'only company stock is reserved');
        $this->assertTrue($order->fresh()->awaitingSupplierProcurement());
        // one variant, one line — the invariant holds
        $this->assertSame(1, SalesOrderLine::query()->where('sales_order_id', $order->id)->where('product_variant_id', $variant->id)->count());
    }

    // --- 3. confirmation blocked while supplier not confirmed --------------

    public function test_confirmation_is_blocked_while_a_procurement_is_still_pending_supplier(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->raise($owner, $order, $line, $supplier, '6.0000'); // left pending

        $this->expectException(ValidationException::class);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
    }

    // --- 4. confirmation blocked when supplier unavailable ----------------

    public function test_confirmation_is_blocked_when_the_supplier_marked_the_requirement_unavailable(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $p->fresh(), ['supplier_availability_status' => 'unavailable']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.supplier_unavailable', 'auditable_id' => $p->id]);
        $this->expectException(ValidationException::class);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
    }

    // --- 5. confirmation allowed when fully covered ----------------------

    public function test_confirmation_succeeds_when_company_plus_confirmed_supplier_cover_the_full_quantity(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));

        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('confirmed', $confirmed->status->value);
    }

    // --- 6. no reservation on the supplier portion ---------------------

    public function test_no_inventory_reservation_is_created_for_the_supplier_portion_before_receipt(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $totalReserved = InventoryReservation::query()->where('organization_id', $org->id)
            ->where('status', InventoryReservationStatus::Active->value)->sum('quantity');
        $this->assertSame(4.0, (float) $totalReserved);
    }

    // --- 7. no fake stock for supplier availability -------------------

    public function test_supplier_availability_creates_no_inventory_balance_or_movement(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->assertDatabaseMissing('inventory_balances', ['warehouse_id' => $depot->id, 'product_variant_id' => $variant->id]);
        $showroomOnHand = InventoryBalance::query()->where('warehouse_id', $showroom->id)->value('on_hand');
        $this->assertSame(4.0, (float) $showroomOnHand, 'company on_hand untouched by procurement');
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
    }

    // --- 8. invoice may be issued before supplier receipt --------------

    public function test_a_confirmed_order_covered_by_procurement_can_be_invoiced_before_receipt(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $invoice = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertNotNull($invoice->id);
        $this->assertTrue($order->fresh()->awaitingSupplierProcurement());
    }

    // --- 9. order at supplier -----------------------------------------

    public function test_ordering_at_the_supplier_requires_a_confirmed_order_and_records_metadata(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));

        // customer order not confirmed yet -> blocked
        try {
            app(OrderProcurementAction::class)->execute($owner, $p->fresh());
            $this->fail('Expected ordering to be blocked before the customer order is confirmed.');
        } catch (ValidationException) {
        }

        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        $this->assertSame(SupplierProcurementStatus::Ordered, $p->status);
        $this->assertNotNull($p->ordered_at);
        $this->assertSame($owner->id, $p->ordered_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.ordered', 'auditable_id' => $p->id]);
    }

    // --- 10. earmarked receipt via the immutable ledger --------------

    public function test_receipt_lands_real_stock_and_immediately_earmarks_it_for_the_order(): void
    {
        // Showroom before: on_hand 5, reserved 0, available 5.
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('5.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '8.0000');
        // 5 company + 3 supplier
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '3.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []);

        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->where('product_variant_id', $variant->id)->first();
        $this->assertSame(8.0, (float) $balance->on_hand, 'on_hand 5 + 3');
        $this->assertSame(8.0, (float) $balance->reserved, 'reserved 5 + 3 earmarked');
        $this->assertSame(0.0, (float) $balance->on_hand - (float) $balance->reserved, 'available unchanged at 0 for the procured qty');

        $movement = InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->first();
        $this->assertNotNull($movement);
        $this->assertSame(3.0, (float) $movement->quantity);
        $this->assertSame(SalesOrderProcurement::class, $movement->reference_type);
        $this->assertSame(SupplierProcurementStatus::Received, $p->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.received', 'auditable_id' => $p->id]);
    }

    // --- 11. immutable ledger not bypassed --------------------------

    public function test_receipt_movement_is_immutable(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('5.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '8.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '3.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());
        app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []);

        $movement = InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->firstOrFail();
        $this->expectException(\LogicException::class);
        $movement->update(['quantity' => '99.0000']);
    }

    // --- 12. receipt into the wrong warehouse triggers a transfer request ---

    public function test_receipt_into_a_warehouse_other_than_the_orders_stock_raises_an_internal_transfer_request(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        // goods physically arrive at the Depot, but the order's company stock sits in the Showroom
        $p = app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $depot, []);

        $this->assertNotNull($p->transfer_request_id);
        $request = TransferRequest::query()->whereKey($p->transfer_request_id)->firstOrFail();
        $this->assertSame($depot->id, $request->source_warehouse_id);
        $this->assertSame($showroom->id, $request->destination_warehouse_id);
        $this->assertSame($order->id, $request->sales_order_id);
        $this->assertSame(6.0, (float) $request->lines()->where('reason', 'order_fulfillment')->sum('quantity'));
        // the earmark sits at the depot until the transfer is received
        $depotReserved = InventoryBalance::query()->where('warehouse_id', $depot->id)->value('reserved');
        $this->assertSame(6.0, (float) $depotReserved);
    }

    // --- 13. same-warehouse receipt creates no transfer request ---------

    public function test_receipt_into_the_orders_own_warehouse_creates_no_transfer_request(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        $p = app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []);
        $this->assertNull($p->transfer_request_id);
        $this->assertSame(0, TransferRequest::query()->where('sales_order_id', $order->id)->count());
    }

    // --- 14. full fulfilment consumes the earmarked reservation -------

    public function test_the_order_can_be_fulfilled_once_every_procurement_is_received(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        // blocked before receipt
        try {
            app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('Fulfilment must be blocked while awaiting supplier procurement.');
        } catch (ValidationException) {
        }

        app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []);
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);

        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->first();
        $this->assertSame(0.0, (float) $balance->on_hand, '10 units left inventory without becoming free stock');
        $this->assertSame(0.0, (float) $balance->reserved);
    }

    // --- 15. procurement quantity equals what the customer needs -----

    public function test_procurement_quantity_cannot_exceed_the_sales_line_quantity(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');

        $this->expectException(ValidationException::class);
        $this->raise($owner, $order, $line, $supplier, '11.0000');
    }

    // --- 16. cancel before supplier order -> plain cancel ------------

    public function test_a_procurement_not_yet_ordered_is_cancelled_cleanly(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));

        $p = app(CancelProcurementAction::class)->execute($owner, $p->fresh(), 'Client a renoncé');
        $this->assertSame(SupplierProcurementStatus::Cancelled, $p->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.cancelled', 'auditable_id' => $p->id]);
    }

    // --- 17. cancel after supplier order -> needs explicit decision --

    public function test_cancelling_an_ordered_procurement_requires_explicit_acknowledgement(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        try {
            app(CancelProcurementAction::class)->execute($owner, $p->fresh(), 'oops');
            $this->fail('Expected a blocked cancel for an already-ordered procurement.');
        } catch (ValidationException) {
        }

        $p = app(CancelProcurementAction::class)->execute($owner, $p->fresh(), 'Fournisseur en rupture', acknowledgeOrdered: true);
        $this->assertSame(SupplierProcurementStatus::Cancelled, $p->status);
    }

    // --- 18. cancelling the sales order blocks on an ordered procurement --

    public function test_cancelling_the_sales_order_is_blocked_by_an_ordered_procurement(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        $this->expectException(ValidationException::class);
        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Annulation client');
    }

    // --- 19. cancelling the sales order cancels not-yet-ordered procurements --

    public function test_cancelling_the_sales_order_cancels_pending_procurements_and_releases_company_stock(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Annulation client');

        $this->assertSame(SupplierProcurementStatus::Cancelled, $p->fresh()->status);
        $reserved = InventoryBalance::query()->where('warehouse_id', $showroom->id)->value('reserved');
        $this->assertSame(0.0, (float) $reserved, 'company reservation released');
    }

    // --- 20. received supplier stock survives order cancellation -----

    public function test_received_supplier_stock_becomes_available_when_the_order_is_cancelled(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());
        app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []);

        // on_hand is now 10 (4 + 6), all reserved
        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Annulation client');

        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->first();
        $this->assertSame(10.0, (float) $balance->on_hand, 'physical stock is retained — never destroyed');
        $this->assertSame(0.0, (float) $balance->reserved, 'the order earmark is released');
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.order_cancelled_stock_retained', 'auditable_id' => $p->id]);
    }

    // --- 21. tenant isolation --------------------------------------

    public function test_a_procurement_cannot_reference_a_supplier_from_another_organization(): void
    {
        [$owner, $org, $store, $showroom, , $variant] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');

        $otherOwner = User::factory()->create();
        $otherOrg = $this->createOrganization($otherOwner, 'Other Org');
        $foreignSupplier = $this->makeSupplier($otherOrg, 'Foreign Supplier');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->raise($owner, $order, $line, $foreignSupplier, '6.0000');
    }

    // --- 22. change supplier before ordering -----------------------

    public function test_the_supplier_can_be_replaced_while_the_procurement_is_not_yet_ordered(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        $other = $this->makeSupplier($org, 'Rabat Meubles');

        $p = app(ChangeProcurementSupplierAction::class)->execute($owner, $p->fresh(), $other);

        $this->assertSame($other->id, $p->supplier_id);
        $this->assertSame(SupplierProcurementStatus::PendingSupplier, $p->status);
        $this->assertSame(SupplierAvailabilityStatus::PendingConfirmation, $p->supplier_availability_status);
    }

    // --- 23. idempotent receipt (double click) --------------------

    public function test_confirming_the_receipt_twice_does_not_double_count_stock(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());

        app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []);
        app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []); // repeat

        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->first();
        $this->assertSame(10.0, (float) $balance->on_hand);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
    }

    // --- 24. transfer receipt hands the earmark to the showroom -------

    public function test_receiving_the_generated_transfer_request_moves_the_earmark_to_the_showroom(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->confirmAvailability($owner, $this->raise($owner, $order, $line, $supplier, '6.0000'));
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $p = app(OrderProcurementAction::class)->execute($owner, $p->fresh());
        $p = app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $depot, []);

        $request = TransferRequest::query()->whereKey($p->transfer_request_id)->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($owner, $request->fresh(), []);
        app(ReceiveTransferRequestAction::class)->execute($owner, $request->fresh());

        $depotBalance = InventoryBalance::query()->where('warehouse_id', $depot->id)->first();
        $showroomBalance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->first();
        $this->assertSame(0.0, (float) $depotBalance->on_hand);
        $this->assertSame(10.0, (float) $showroomBalance->on_hand, '4 company + 6 procured now all in the showroom');
        $this->assertSame(10.0, (float) $showroomBalance->reserved, 'all earmarked for the order');
    }

    // --- 25. POS sales are out of scope for procurement -------------

    /**
     * A POS-sourced order may raise a supplier procurement too — see the
     * "POS + Supplier Procurement" integration (tests/Feature/Procurement/PosSupplierProcurementTest.php)
     * for the full POS-side coverage. Both surfaces operate on the same
     * SalesOrderProcurement rows.
     */
    public function test_a_pos_sourced_order_can_also_raise_a_supplier_procurement(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        $order = $this->createDraftOrder($owner, $org, $store, null);
        $line = $this->addCatalogLine($owner, $order->fresh(), $variant, $showroom, ['quantity' => '10.0000']);
        $order->source = \App\Enums\SalesOrderSource::Pos;
        $order->pos_warehouse_id = $showroom->id;
        $order->save();

        $procurement = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $this->assertSame('6.0000', $procurement->quantity);
        $this->assertSame($order->id, $procurement->sales_order_id);
    }
}
