<?php

namespace Tests\Feature\Procurement;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Procurement\CancelProcurementAction;
use App\Actions\Procurement\ChangeProcurementSupplierAction;
use App\Actions\Procurement\CreateProcurementAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\OrderProcurementAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Actions\Procurement\RecordSupplierAvailabilityAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SupplierProcurementStatus;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReservationManager;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesTestCase;

/**
 * Procurement requirement lifecycle from a Sales Order shortage, after the
 * procurement cleanup: a requirement is a purchasing need ("À commander"),
 * ordered ONLY through a purchase order, received ONLY through a purchase order
 * line, and the order is confirmable only once the goods have physically
 * arrived. Supplier availability is pre-sale evidence, not a requirement state.
 */
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

    /** The only way to order: a real purchase order. */
    private function placeOrder(User $owner, Organization $org, SalesOrderProcurement $p): PurchaseOrder
    {
        return app(CreatePurchaseOrderFromProcurementRequirementsAction::class)
            ->execute($owner, $org, $p->supplier()->firstOrFail(), [$p->id], ['expected_delivery_date' => now()->addDays(5)->toDateString()]);
    }

    /** The only way to receive: against the requirement's purchase order line. */
    private function receive(User $owner, SalesOrderProcurement $p, Warehouse $warehouse, array $data = []): SalesOrderProcurement
    {
        $line = $p->purchaseOrderLines()->firstOrFail();

        return app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $warehouse, [...$data, 'purchase_order_line_id' => $line->id]);
    }

    /** Raise → purchase order → full receipt while the order is a draft. */
    private function procureAndReceive(User $owner, Organization $org, SalesOrder $order, SalesOrderLine $line, Supplier $supplier, string $qty, Warehouse $at): SalesOrderProcurement
    {
        $p = $this->raise($owner, $order, $line, $supplier, $qty);
        $this->placeOrder($owner, $org, $p);

        return $this->receive($owner, $p->fresh(), $at);
    }

    /**
     * Data as left by the pre-cleanup workflow: an order CONFIRMED on a supplier
     * commitment (company portion reserved, supplier portion not yet received).
     * Historical rows stay untouched and must still be orderable / receivable.
     *
     * @return array{SalesOrder, SalesOrderProcurement}
     */
    private function legacyConfirmedOrder(User $owner, Organization $org, Store $store, ProductVariant $variant, Warehouse $showroom, Supplier $supplier): array
    {
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $line->allocations()->delete();
        $allocation = new SalesOrderInventoryAllocation;
        $allocation->organization_id = $org->id;
        $allocation->sales_order_line_id = $line->id;
        $allocation->warehouse_id = $showroom->id;
        $allocation->quantity = '4.0000';
        $allocation->save();
        $reservation = app(InventoryReservationManager::class)->reserve($owner, $org, $showroom, $variant, '4.0000', SalesOrderInventoryAllocation::class, $allocation->id, $order->order_number);
        $allocation->inventory_reservation_id = $reservation->id;
        $allocation->save();
        $order->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $owner->id])->save();

        return [$order->fresh(), $p->fresh()];
    }

    // --- requirement = purchasing need, born "À commander" -------------------

    public function test_a_requirement_is_a_need_to_order_not_an_availability_question(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');

        $procurement = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $this->assertSame('6.0000', $procurement->quantity);
        $this->assertSame(SupplierProcurementStatus::SupplierConfirmed, $procurement->status);
        $this->assertSame('À commander', $procurement->status->label());
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.created', 'auditable_id' => $procurement->id]);
    }

    public function test_recording_supplier_availability_on_a_requirement_is_retired(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');

        foreach (['confirmed_available', 'unavailable', 'pending_confirmation'] as $status) {
            try {
                app(RecordSupplierAvailabilityAction::class)->execute($owner, $p->fresh(), ['supplier_availability_status' => $status]);
                $this->fail('Availability is evidence, never a requirement state.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('supplier_availability_status', $e->errors());
            }
        }
        $this->assertSame(SupplierProcurementStatus::SupplierConfirmed, $p->fresh()->status);
    }

    // --- ordering only through a purchase order --------------------------

    public function test_a_requirement_cannot_be_marked_ordered_without_a_purchase_order(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');

        try {
            app(OrderProcurementAction::class)->execute($owner, $p->fresh());
            $this->fail('"Ordered" must mean a purchase order exists.');
        } catch (ValidationException) {
        }
        $this->assertSame(SupplierProcurementStatus::SupplierConfirmed, $p->fresh()->status);

        $po = $this->placeOrder($owner, $org, $p);
        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertSame(SupplierProcurementStatus::Ordered, $p->fresh()->status);
        $this->assertNotNull($p->fresh()->ordered_at);
        $this->assertSame('6.0000', $po->lines()->sole()->quantity);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.ordered', 'auditable_id' => $p->id]);
    }

    public function test_a_requirement_cannot_be_received_without_a_purchase_order_line(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $this->expectException(ValidationException::class);
        app(ReceiveProcurementAction::class)->execute($owner, $p->fresh(), $showroom, []);
    }

    // --- confirmation waits for the goods --------------------------------

    public function test_confirmation_is_blocked_until_the_supplier_goods_are_physically_received(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $this->assertConfirmationBlocked($owner, $order);   // to order
        $this->placeOrder($owner, $org, $p);
        $this->assertConfirmationBlocked($owner, $order);   // ordered, not received

        $this->receive($owner, $p->fresh(), $showroom);
        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('confirmed', $confirmed->status->value);
        $this->assertSame(10.0, (float) $line->fresh()->allocations()->sum('quantity'));
        $this->assertSame(10.0, (float) InventoryBalance::query()->where('warehouse_id', $showroom->id)->value('reserved'));
        $this->assertSame(1, SalesOrderLine::query()->where('sales_order_id', $order->id)->where('product_variant_id', $variant->id)->count());
    }

    public function test_a_requirement_alone_creates_no_stock_reservation_or_movement(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');
        $this->placeOrder($owner, $org, $p);

        $this->assertDatabaseMissing('inventory_balances', ['warehouse_id' => $depot->id, 'product_variant_id' => $variant->id]);
        $this->assertSame(4.0, (float) InventoryBalance::query()->where('warehouse_id', $showroom->id)->value('on_hand'));
        $this->assertSame(0, InventoryReservation::query()->where('organization_id', $org->id)->where('status', InventoryReservationStatus::Active->value)->count());
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
    }

    public function test_an_order_waiting_for_supplier_goods_cannot_be_invoiced_as_a_confirmed_sale(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->placeOrder($owner, $org, $this->raise($owner, $order, $line, $supplier, '6.0000'));

        $this->assertConfirmationBlocked($owner, $order);
        $this->expectException(ValidationException::class);
        app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh());
    }

    // --- receipt through the purchase order ---------------------------------

    public function test_receipt_lands_real_stock_and_holds_it_for_the_draft_order(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('5.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '8.0000');

        $p = $this->procureAndReceive($owner, $org, $order, $line, $supplier, '3.0000', $showroom);

        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->where('product_variant_id', $variant->id)->first();
        $this->assertSame(8.0, (float) $balance->on_hand, 'on_hand 5 + 3');
        $this->assertSame(3.0, (float) $balance->reserved, 'the 3 received units are held for the order');
        $movement = InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->firstOrFail();
        $this->assertSame(3.0, (float) $movement->quantity);
        $this->assertSame(SupplierProcurementStatus::Received, $p->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.received', 'auditable_id' => $p->id]);

        $this->expectException(\LogicException::class);
        $movement->quantity = '99.0000';
        $movement->save();
    }

    public function test_receiving_the_same_purchase_order_line_twice_never_double_counts_stock(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->procureAndReceive($owner, $org, $order, $line, $supplier, '6.0000', $showroom);

        try {
            $this->receive($owner, $p->fresh(), $showroom);
            $this->fail('Nothing is left to receive on that purchase order line.');
        } catch (ValidationException) {
        }

        $this->assertSame(10.0, (float) InventoryBalance::query()->where('warehouse_id', $showroom->id)->value('on_hand'));
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
    }

    public function test_the_order_is_confirmed_and_fulfilled_once_every_procurement_is_received(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->procureAndReceive($owner, $org, $order, $line, $supplier, '6.0000', $showroom);

        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);

        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->first();
        $this->assertSame(0.0, (float) $balance->on_hand, '10 units left inventory without becoming free stock');
        $this->assertSame(0.0, (float) $balance->reserved);
    }

    // --- historical (pre-cleanup) confirmed orders stay workable ------------

    public function test_a_historically_confirmed_order_is_ordered_and_received_through_a_purchase_order(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant, $supplier] = $this->context('4.0000');
        [$order, $p] = $this->legacyConfirmedOrder($owner, $org, $store, $variant, $showroom, $supplier);

        // Fulfilment still waits for the supplier goods.
        try {
            app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('Fulfilment must be blocked while awaiting supplier procurement.');
        } catch (ValidationException) {
        }

        $this->placeOrder($owner, $org, $p);
        // Goods arrive at the depot, the order's stock sits in the showroom → internal transfer.
        $p = $this->receive($owner, $p->fresh(), $depot);
        $request = TransferRequest::query()->whereKey($p->transfer_request_id)->firstOrFail();
        $this->assertSame([$depot->id, $showroom->id, $order->id], [$request->source_warehouse_id, $request->destination_warehouse_id, $request->sales_order_id]);
        $this->assertSame(6.0, (float) InventoryBalance::query()->where('warehouse_id', $depot->id)->value('reserved'));

        app(PrepareTransferRequestAction::class)->execute($owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($owner, $request->fresh(), []);
        app(ReceiveTransferRequestAction::class)->execute($owner, $request->fresh());
        $showroomBalance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->first();
        $this->assertSame(10.0, (float) $showroomBalance->on_hand, '4 company + 6 procured now all in the showroom');
        $this->assertSame(10.0, (float) $showroomBalance->reserved, 'all earmarked for the order');

        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);
    }

    public function test_a_legacy_requirement_marked_ordered_without_a_purchase_order_is_regularised_on_one(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');
        $p->forceFill(['status' => SupplierProcurementStatus::Ordered, 'ordered_at' => now()])->save(); // pre-cleanup data

        $po = $this->placeOrder($owner, $org, $p);
        $this->assertSame('6.0000', $po->lines()->sole()->quantity);
        $this->receive($owner, $p->fresh(), $showroom);
        $this->assertSame(SupplierProcurementStatus::Received, $p->fresh()->status);
    }

    // --- guards ---------------------------------------------------------------

    public function test_procurement_quantity_cannot_exceed_the_sales_line_quantity(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');

        $this->expectException(ValidationException::class);
        $this->raise($owner, $order, $line, $supplier, '11.0000');
    }

    public function test_a_requirement_not_yet_ordered_is_cancelled_cleanly(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $p = app(CancelProcurementAction::class)->execute($owner, $p->fresh(), 'Client a renoncé');
        $this->assertSame(SupplierProcurementStatus::Cancelled, $p->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.cancelled', 'auditable_id' => $p->id]);
    }

    public function test_cancelling_a_requirement_on_an_active_purchase_order_is_refused(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');
        $this->placeOrder($owner, $org, $p);

        $this->expectException(ValidationException::class);
        app(CancelProcurementAction::class)->execute($owner, $p->fresh(), 'Fournisseur en rupture', acknowledgeOrdered: true);
    }

    public function test_cancelling_the_draft_order_cancels_requirements_not_yet_ordered(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');

        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Annulation client');

        $this->assertSame(SupplierProcurementStatus::Cancelled, $p->fresh()->status);
        $this->assertSame(0.0, (float) InventoryBalance::query()->where('warehouse_id', $showroom->id)->value('reserved'));
    }

    public function test_received_supplier_stock_becomes_free_when_the_draft_order_is_cancelled(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $this->procureAndReceive($owner, $org, $order, $line, $supplier, '6.0000', $showroom);

        app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Annulation client');

        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->first();
        $this->assertSame(10.0, (float) $balance->on_hand, 'physical stock is retained — never destroyed');
        $this->assertSame(0.0, (float) $balance->reserved, 'the order earmark is released');
    }

    public function test_a_procurement_cannot_reference_a_supplier_from_another_organization(): void
    {
        [$owner, $org, $store, $showroom, , $variant] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');

        $otherOwner = User::factory()->create();
        $otherOrg = $this->createOrganization($otherOwner, 'Other Org');
        $foreignSupplier = $this->makeSupplier($otherOrg, 'Foreign Supplier');

        $this->expectException(HttpException::class);
        $this->raise($owner, $order, $line, $foreignSupplier, '6.0000');
    }

    public function test_the_supplier_can_be_replaced_while_the_requirement_is_not_yet_ordered(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        [$order, $line] = $this->draftWithLine($owner, $org, $store, $variant, $showroom, '10.0000');
        $p = $this->raise($owner, $order, $line, $supplier, '6.0000');
        $other = $this->makeSupplier($org, 'Rabat Meubles');

        $p = app(ChangeProcurementSupplierAction::class)->execute($owner, $p->fresh(), $other);

        $this->assertSame($other->id, $p->supplier_id);
        $this->assertTrue($p->fresh()->status->isEditable());
    }

    public function test_a_pos_sourced_order_can_also_raise_a_requirement(): void
    {
        [$owner, $org, $store, $showroom, , $variant, $supplier] = $this->context('4.0000');
        $order = $this->createDraftOrder($owner, $org, $store, null);
        $line = $this->addCatalogLine($owner, $order->fresh(), $variant, $showroom, ['quantity' => '10.0000']);
        $order->source = SalesOrderSource::Pos;
        $order->pos_warehouse_id = $showroom->id;
        $order->save();

        $procurement = $this->raise($owner, $order, $line, $supplier, '6.0000');

        $this->assertSame('6.0000', $procurement->quantity);
        $this->assertSame($order->id, $procurement->sales_order_id);
    }

    private function assertConfirmationBlocked(User $owner, SalesOrder $order): void
    {
        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('The order must wait for the supplier goods.');
        } catch (ValidationException) {
        }
        $this->assertSame('draft', $order->fresh()->status->value);
    }
}
