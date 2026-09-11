<?php

namespace Tests\Feature\Procurement;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Pos\CreatePosSaleAction;
use App\Actions\Procurement\ChangeProcurementSupplierAction;
use App\Actions\Procurement\CreateProcurementAction;
use App\Actions\Procurement\OrderProcurementAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Actions\Procurement\RecordSupplierAvailabilityAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
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
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PosStockAllocator;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosTestCase;

class PosSupplierProcurementTest extends PosTestCase
{
    /** @return array{User, Organization, Store, Warehouse, ProductVariant, Supplier} */
    private function context(string $showroomStock = '0.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $showroom = $this->createWarehouse($organization, 'Showroom', 'SHW');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'MV7', 'MV7', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        if ($showroomStock !== '0.0000') {
            $this->openStock($owner, $organization, $showroom, $variant, $showroomStock);
        }
        $supplier = new Supplier;
        $supplier->organization_id = $organization->getKey();
        $supplier->name = 'Casa Sin';
        $supplier->active = true;
        $supplier->save();

        return [$owner, $organization, $store, $showroom, $variant, $supplier];
    }

    /** @return array{SalesOrder, SalesOrderLine} */
    private function posDraftWithLine(User $owner, Organization $org, Store $store, Warehouse $warehouse, ProductVariant $variant, string $qty): array
    {
        $draft = $this->createPosDraft($owner, $org, $store, $warehouse);
        $line = app(SaveSalesOrderLineAction::class)->execute($owner, $draft, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(), 'warehouse_id' => $warehouse->getKey(),
            'quantity' => $qty, 'discount_type' => 'none', 'discount_value' => '0.0000',
        ]);

        return [$draft->fresh(), $line->fresh()];
    }

    // --- 1/2. zero-stock Product stays visible and can be added -------------

    public function test_pos_search_returns_a_stock_zero_product_and_it_can_be_added_to_the_cart(): void
    {
        [$owner, $org, $store, $showroom, $variant] = $this->context('0.0000');

        $response = $this->actingAs($owner)->getJson('/pos/products?'.http_build_query(['warehouse_id' => $showroom->id]));
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $variant->id);
        $this->assertNotNull($row, 'the zero-stock variant must still be returned by the search');
        $this->assertSame('0.0000', $row['stock']['total_available']);

        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '1.0000');
        $this->assertSame('1.0000', $line->quantity);
        $this->assertSame(1, $draft->lines()->count());
    }

    // --- 3. same variant reuses the existing line ---------------------------

    public function test_clicking_the_same_variant_twice_increases_the_existing_line_without_duplicating_it(): void
    {
        [$owner, $org, $store, $showroom, $variant] = $this->context('0.0000');
        $draft = $this->createPosDraft($owner, $org, $store, $showroom);

        $this->actingAs($owner)->postJson("/pos/drafts/{$draft->id}/lines", [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $showroom->id,
            'quantity' => '1', 'discount_type' => 'none', 'discount_value' => '0',
        ])->assertOk();
        $this->actingAs($owner)->postJson("/pos/drafts/{$draft->id}/lines", [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $showroom->id,
            'quantity' => '1', 'discount_type' => 'none', 'discount_value' => '0',
        ])->assertOk();

        $this->assertSame(1, SalesOrderLine::query()->where('sales_order_id', $draft->id)->count());
        $this->assertSame('2.0000', SalesOrderLine::query()->where('sales_order_id', $draft->id)->value('quantity'));
    }

    // --- 4/5. procurement deficit ------------------------------------------

    public function test_company_zero_quantity_three_yields_a_deficit_of_three(): void
    {
        [$owner, $org, $store, $showroom, $variant] = $this->context('0.0000');
        $draft = $this->createPosDraft($owner, $org, $store, $showroom);

        $response = $this->actingAs($owner)->postJson("/pos/drafts/{$draft->id}/lines", [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $showroom->id,
            'quantity' => '3', 'discount_type' => 'none', 'discount_value' => '0',
        ]);
        $response->assertOk();
        $line = $response->json('active_sale.lines.0');
        $this->assertSame('0.0000', $line['company_covered']);
        $this->assertSame('3.0000', $line['to_procure']);
        $this->assertTrue($response->json('active_sale.procurement_deficit'));
    }

    public function test_company_three_quantity_five_yields_a_deficit_of_two(): void
    {
        [$owner, $org, $store, $showroom, $variant] = $this->context('3.0000');
        $draft = $this->createPosDraft($owner, $org, $store, $showroom);

        $response = $this->actingAs($owner)->postJson("/pos/drafts/{$draft->id}/lines", [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $showroom->id,
            'quantity' => '5', 'discount_type' => 'none', 'discount_value' => '0',
        ]);
        $response->assertOk();
        $line = $response->json('active_sale.lines.0');
        $this->assertSame('3.0000', $line['company_covered']);
        $this->assertSame('2.0000', $line['to_procure']);
    }

    // --- 6. in-stock POS flow is unchanged ----------------------------------

    public function test_a_fully_in_stock_pos_sale_is_confirmed_paid_and_fulfilled_as_before(): void
    {
        [$owner, $org, $store, $showroom, $variant] = $this->context('5.0000');
        [$draft] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '2.0000');
        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');

        $total = $draft->fresh()->total_incl_tax;
        $completed = app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft, [$this->posPayment($account, $total)],
        ));

        $this->assertSame('confirmed', $completed->status->value);
        $this->assertSame('fulfilled', $completed->fulfillment_status->value);
        $this->assertSame(0, SalesOrderProcurement::query()->where('sales_order_id', $completed->id)->count());
    }

    // --- 7. no orphan procurement from an abandoned cart --------------------

    public function test_adding_a_zero_stock_line_creates_no_procurement_record_by_itself(): void
    {
        [$owner, $org, $store, $showroom, $variant] = $this->context('0.0000');
        [$draft] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '3.0000');

        $this->assertSame(0, SalesOrderProcurement::query()->where('sales_order_id', $draft->id)->count());

        // Even discarding the cart afterwards leaves no procurement behind.
        $this->actingAs($owner)->deleteJson("/pos/drafts/{$draft->id}")->assertOk();
        $this->assertSame(0, SalesOrderProcurement::query()->count());
    }

    // --- 8. existing Supplier used from the POS sourcing endpoints ----------

    public function test_pos_sourcing_creates_a_procurement_against_the_existing_supplier_via_json_endpoints(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '3.0000');

        $response = $this->actingAs($owner)->postJson("/sales/orders/{$draft->id}/procurements", [
            'sales_order_line_id' => $line->id, 'supplier_id' => $supplier->id, 'quantity' => '3',
        ]);
        $response->assertCreated();
        $procurementId = $response->json('data.id');

        $this->assertSame(1, Supplier::query()->count(), 'no second supplier table/row is created');
        $procurement = SalesOrderProcurement::query()->whereKey($procurementId)->firstOrFail();
        $this->assertSame($supplier->id, $procurement->supplier_id);
        $this->assertSame($line->id, $procurement->sales_order_line_id);

        $this->actingAs($owner)->patchJson("/procurement/{$procurementId}/availability", [
            'supplier_availability_status' => 'confirmed_available', 'quantity' => '3',
        ])->assertOk();
        $this->assertSame(SupplierProcurementStatus::SupplierConfirmed, $procurement->fresh()->status);
    }

    // --- 9. confirmed supplier quantity covers the POS shortage -------------

    public function test_confirmed_procurement_lets_the_pos_special_order_be_confirmed_and_paid(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '1.0000');
        $procurement = app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '1.0000']);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement, ['supplier_availability_status' => 'confirmed_available']);

        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        $completed = app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [],
        ));

        $this->assertSame('confirmed', $completed->status->value);
        $this->assertSame('unfulfilled', $completed->fulfillment_status->value);
        $this->assertTrue($completed->awaitingSupplierProcurement());
    }

    // --- 10. pending supplier blocks confirmation ---------------------------

    public function test_pending_supplier_availability_blocks_the_pos_order_from_being_confirmed(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '1.0000');
        app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '1.0000']); // left pending

        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        $this->expectException(ValidationException::class);
        app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [],
        ));
    }

    // --- 11. insufficient supplier coverage blocks confirmation -------------

    public function test_a_confirmed_but_insufficient_supplier_quantity_still_blocks_confirmation(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '3.0000');
        $procurement = app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '2.0000']);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement, ['supplier_availability_status' => 'confirmed_available', 'quantity' => '2.0000']);

        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        $this->expectException(ValidationException::class);
        app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [],
        ));
    }

    // --- 12. supplier unavailable can be replaced ---------------------------

    public function test_an_unavailable_supplier_can_be_replaced_and_the_order_then_confirmed(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '1.0000');
        $procurement = app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '1.0000']);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement->fresh(), ['supplier_availability_status' => 'unavailable']);
        $this->assertSame(SupplierProcurementStatus::Unavailable, $procurement->fresh()->status);

        $other = new Supplier;
        $other->organization_id = $org->getKey();
        $other->name = 'Rabat Meubles';
        $other->active = true;
        $other->save();
        $procurement = app(ChangeProcurementSupplierAction::class)->execute($owner, $procurement->fresh(), $other);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement->fresh(), ['supplier_availability_status' => 'confirmed_available']);

        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        $completed = app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [],
        ));
        $this->assertSame('confirmed', $completed->status->value);
    }

    // --- 13/14. only company stock is reserved; no fake balance -------------

    public function test_only_company_stock_is_reserved_and_the_supplier_portion_creates_no_fake_balance(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('3.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '5.0000');
        $procurement = app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '2.0000']);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement->fresh(), ['supplier_availability_status' => 'confirmed_available']);

        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [],
        ));

        $reserved = InventoryReservation::query()->where('organization_id', $org->id)
            ->where('status', 'active')->sum('quantity');
        $this->assertSame(3.0, (float) $reserved, 'only the 3 company units are reserved');
        $balance = InventoryBalance::query()->where('warehouse_id', $showroom->id)->where('product_variant_id', $variant->id)->first();
        $this->assertSame(3.0, (float) $balance->on_hand, 'the supplier portion never touched on_hand');
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', 'supplier_receipt')->count());
    }

    // --- 15/16/17. invoice + payment before receipt; fulfilment blocked -----

    public function test_pos_special_order_can_be_invoiced_and_paid_before_receipt_but_not_fulfilled(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '1.0000');
        $procurement = app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '1.0000']);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement->fresh(), ['supplier_availability_status' => 'confirmed_available']);

        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        // Partial-but-nonzero payment while goods have not arrived.
        $unitTotal = Decimal::divide($draft->fresh()->total_incl_tax, '2', 4);
        $completed = app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [$this->posPayment($account, $unitTotal, ['cash_received' => $unitTotal])],
        ));

        $invoice = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $completed->fresh());
        $this->assertNotNull($invoice->id);

        $this->expectException(ValidationException::class);
        app(FulfillSalesOrderAction::class)->execute($owner, $completed->fresh());
    }

    // --- 18. receipt uses the existing earmarking workflow -------------------

    public function test_receipt_earmarks_the_goods_and_then_fulfilment_succeeds(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '1.0000');
        $procurement = app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '1.0000']);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement->fresh(), ['supplier_availability_status' => 'confirmed_available']);
        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        $completed = app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [],
        ));

        $procurement = SalesOrderProcurement::query()->where('sales_order_id', $completed->id)->firstOrFail();
        app(OrderProcurementAction::class)->execute($owner, $procurement->fresh());
        app(ReceiveProcurementAction::class)->execute($owner, $procurement->fresh(), $showroom, []);

        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $completed->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);
    }

    // --- 19. authoritative re-check at confirmation --------------------------

    public function test_company_stock_movement_between_cart_and_checkout_is_re_validated_at_confirmation(): void
    {
        [$owner, $org, $store, $showroom, $variant, $supplier] = $this->context('3.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '5.0000');
        $procurement = app(CreateProcurementAction::class)->execute($owner, $draft, $line, $supplier, ['quantity' => '2.0000']);
        app(RecordSupplierAvailabilityAction::class)->execute($owner, $procurement->fresh(), ['supplier_availability_status' => 'confirmed_available']);

        // Another operation consumes 2 of the 3 company units before checkout.
        $other = $this->createDraftOrder($owner, $org, $store, null);
        app(SaveSalesOrderLineAction::class)->execute($owner, $other, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->getKey(), 'warehouse_id' => $showroom->getKey(),
            'quantity' => '2.0000', 'discount_type' => 'none', 'discount_value' => '0.0000',
        ]);
        app(ConfirmSalesOrderAction::class)->execute($owner, $other->fresh());

        $account = $this->createPosAccount($org, 'cash', 'POS-CASH');
        $this->expectException(ValidationException::class);
        app(CreatePosSaleAction::class)->execute($owner, $org, $store, $this->posDraftCheckoutPayload(
            $draft->fresh(), [],
        ));
    }

    // --- 20. tenant isolation -------------------------------------------------

    public function test_a_pos_order_cannot_be_sourced_from_another_organizations_supplier(): void
    {
        [$owner, $org, $store, $showroom, $variant] = $this->context('0.0000');
        [$draft, $line] = $this->posDraftWithLine($owner, $org, $store, $showroom, $variant, '1.0000');

        $otherOwner = User::factory()->create();
        $otherOrg = $this->createOrganization($otherOwner, 'Other Org');
        $foreignSupplier = new Supplier;
        $foreignSupplier->organization_id = $otherOrg->getKey();
        $foreignSupplier->name = 'Foreign Supplier';
        $foreignSupplier->active = true;
        $foreignSupplier->save();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CreateProcurementAction::class)->execute($owner, $draft, $line, $foreignSupplier, ['quantity' => '1.0000']);
    }
}
