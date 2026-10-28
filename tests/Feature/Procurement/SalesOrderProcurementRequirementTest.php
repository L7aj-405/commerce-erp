<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\CancelPurchaseOrderAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Quotations\CancelQuotationSupplierRequestAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuotationSupplierAvailabilityStatus;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Attention\AttentionScanner;
use App\Services\Mcp\Sales\SalesOrderReadService;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

/**
 * Post-quotation procurement P1: requirements derived from the accepted
 * quotation's supplier availability, purchase orders created from them, the
 * Sales Order procurement read model and the Work attention.
 */
class SalesOrderProcurementRequirementTest extends QuotationTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private ProductVariant $shure;

    private ProductVariant $mackie;

    private ProductVariant $xlr;

    private Supplier $mifa;

    private Supplier $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00'));

        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization);
        $tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $product = fn (string $name, string $sku) => $this->createProduct($this->organization, $name, $sku, ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->firstOrFail();
        $this->shure = $product('Shure MV7X', 'SHU-MV7X');
        $this->mackie = $product('Mackie ProFX10v3', 'MAC-PFX10');
        $this->xlr = $product('Cordial XLR', 'COR-XLR');
        $this->activate($this->owner, $this->organization, $this->store);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->shure, '5.0000');
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->mackie, '3.0000');
        // No internal stock of the XLR cable.

        $this->mifa = $this->supplier($this->organization, 'MIFA');
        $this->other = $this->supplier($this->organization, 'Autre fournisseur');
    }

    // ── 1, 2, 16, 17, 20, 21 — derivation at conversion ─────────────────────

    public function test_a_fully_stocked_conversion_is_unchanged_and_needs_no_procurement(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $this->addCatalogQuotationLine($this->owner, $quotation, $this->shure, ['quantity' => '2']);
        $result = $this->convert($this->issueQuotation($this->owner, $quotation, false));

        $this->assertSame([], $result['stock_warnings']);
        $this->assertFalse($result['procurement']['required']);
        $this->assertSame(0, SalesOrderProcurement::query()->count());
        $plan = $this->plan($result['order']);
        $this->assertSame('covered', $plan['summary']['state']);
        $this->assertSame(SalesOrderProcurementPlanner::COVERED, $plan['lines'][0]['state']);
        $this->assertSame('2.0000', $plan['lines'][0]['internal_stock_covered_quantity']);
        // Confirmation reserves the stock exactly as before.
        $this->assertSame('confirmed', app(ConfirmSalesOrderAction::class)->execute($this->owner, $result['order']->fresh())->status->value);
    }

    public function test_a_shortage_confirmed_by_a_supplier_becomes_a_procurement_requirement(): void
    {
        [$quotation, $line] = $this->quotationWithXlr('2');
        $request = $this->record($quotation, $this->requestSupplier($quotation, $line, $this->mifa), 'available', '2');
        $result = $this->convert($this->issueQuotation($this->owner, $quotation, false));
        $order = $result['order'];

        $requirement = SalesOrderProcurement::query()->sole();
        $this->assertSame(SupplierProcurementStatus::SupplierConfirmed, $requirement->status);
        $this->assertSame(SupplierAvailabilityStatus::ConfirmedAvailable, $requirement->supplier_availability_status);
        $this->assertSame($this->mifa->id, $requirement->supplier_id);
        $this->assertSame('2.0000', $requirement->quantity);
        $this->assertSame($order->lines()->where('product_variant_id', $this->xlr->id)->value('id'), $requirement->sales_order_line_id);
        $this->assertSame($quotation->id, $requirement->source_quotation_id);
        $this->assertSame($line->id, $requirement->source_quotation_line_id);
        $this->assertSame($request->id, $requirement->source_supplier_request_id);
        $this->assertTrue($result['procurement']['required']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.created', 'auditable_id' => $requirement->id]);
        // The supplier answer is evidence only: untouched, never a purchase order.
        $this->assertSame(QuotationSupplierAvailabilityStatus::Available, $request->fresh()->status);
        $this->assertSame(0, PurchaseOrder::query()->count());

        $plan = $this->plan($order);
        $xlr = $this->planLine($plan, $this->xlr);
        $this->assertSame(SalesOrderProcurementPlanner::REQUIRED, $xlr['state']);
        $this->assertSame('2.0000', $xlr['procurement_quantity']);
        $this->assertSame('2.0000', $xlr['remaining_to_order']);
        $this->assertSame(['id' => $this->mifa->id, 'name' => 'MIFA'], $xlr['supplier']);
        $this->assertSame(['status' => 'confirmed_available', 'source' => 'quotation'], $xlr['availability']);
        $this->assertSame($quotation->fresh()->quotation_number, $xlr['source_quotation']['number']);
        // The draft order can be ordered from the supplier; it is confirmed only after receipt.
        $this->assertTrue($plan['summary']['ready_for_ordering']);
        $this->assertTrue($plan['summary']['confirmation_waits_for_receipt']);
        $this->assertSame([['supplier' => ['id' => $this->mifa->id, 'name' => 'MIFA'], 'requirement_ids' => [$requirement->id], 'line_count' => 1]], $plan['summary']['suppliers']);
    }

    public function test_a_shortage_without_supplier_confirmation_is_unresolved(): void
    {
        [$quotation] = $this->quotationWithXlr('2');
        // Manual attestation without a known supplier: no supplier to order from.
        $order = $this->convert($this->issueQuotation($this->owner, $quotation, true))['order'];

        $this->assertSame(0, SalesOrderProcurement::query()->count());
        $plan = $this->plan($order);
        $xlr = $this->planLine($plan, $this->xlr);
        $this->assertSame(SalesOrderProcurementPlanner::UNRESOLVED, $xlr['state']);
        $this->assertSame('no_supplier_confirmation', $xlr['unresolved_reason']);
        $this->assertNull($xlr['supplier']);
        $this->assertSame(1, $plan['summary']['unresolved_count']);
        $this->assertSame([], $plan['summary']['suppliers']);
    }

    public function test_a_revision_reducing_the_confirmed_quantity_yields_the_current_shortage(): void
    {
        // Live scenario: DEV-340 → R1 with 2 × Shure, 1 × Mackie, 2 × XLR (3 confirmed by MIFA).
        $original = $this->createQuotation($this->owner, $this->organization, $this->store);
        $this->addCatalogQuotationLine($this->owner, $original, $this->xlr, ['quantity' => '3']);
        $revision = $this->reviseQuotation($this->owner, $this->issueQuotation($this->owner, $original, true));
        $this->addCatalogQuotationLine($this->owner, $revision, $this->shure, ['quantity' => '2']);
        $this->addCatalogQuotationLine($this->owner, $revision, $this->mackie, ['quantity' => '1']);
        $revisionXlr = $revision->lines()->where('product_variant_id', $this->xlr->id)->sole();
        $this->record($revision, $this->requestSupplier($revision, $revisionXlr, $this->mifa), 'available', '3');
        $this->updateLine($revision, $revisionXlr, $this->xlr, '2');

        $order = $this->convert($this->issueQuotation($this->owner, $revision->fresh(), false))['order'];

        $requirement = SalesOrderProcurement::query()->sole();
        $this->assertSame('2.0000', $requirement->quantity, 'The requirement is the current shortage, not the 3 once confirmed.');
        $this->assertSame($this->mifa->id, $requirement->supplier_id);
        $this->assertSame($revision->id, $requirement->source_quotation_id);
        $plan = $this->plan($order);
        $this->assertSame(SalesOrderProcurementPlanner::COVERED, $this->planLine($plan, $this->shure)['state']);
        $this->assertSame(SalesOrderProcurementPlanner::COVERED, $this->planLine($plan, $this->mackie)['state']);
        $this->assertSame(SalesOrderProcurementPlanner::REQUIRED, $this->planLine($plan, $this->xlr)['state']);
        $this->assertSame(1, $plan['summary']['required_line_count']);

        $purchaseOrder = $this->createPurchaseOrder($this->owner, [$requirement->id]);
        $this->assertSame('2.0000', $purchaseOrder->lines->sole()->quantity);
        $plan = $this->plan($order);
        $this->assertSame(SalesOrderProcurementPlanner::ORDERED, $this->planLine($plan, $this->xlr)['state']);
        $this->assertSame('ordered', $plan['summary']['state']);
    }

    public function test_supplier_confirmations_of_a_superseded_version_are_not_used(): void
    {
        $original = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $original, $this->xlr, ['quantity' => '2']);
        $this->record($original, $this->requestSupplier($original, $line, $this->mifa), 'available', '2');
        $revision = $this->reviseQuotation($this->owner, $this->issueQuotation($this->owner, $original, false));

        // The revision is attested manually (no supplier answer of its own).
        $order = $this->convert($this->issueQuotation($this->owner, $revision, true))['order'];

        $this->assertSame(0, SalesOrderProcurement::query()->count());
        $this->assertSame(SalesOrderProcurementPlanner::UNRESOLVED, $this->planLine($this->plan($order), $this->xlr)['state']);
    }

    public function test_unavailable_or_cancelled_supplier_answers_are_not_confirmations(): void
    {
        [$quotation, $line] = $this->quotationWithXlr('2');
        $this->record($quotation, $this->requestSupplier($quotation, $line, $this->mifa), 'unavailable');
        $cancelled = $this->record($quotation, $this->requestSupplier($quotation, $line, $this->other), 'available', '2');
        app(CancelQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $cancelled);

        $order = $this->convert($this->issueQuotation($this->owner, $quotation->fresh(), true))['order'];

        $this->assertSame(0, SalesOrderProcurement::query()->count());
        $this->assertSame(SalesOrderProcurementPlanner::UNRESOLVED, $this->planLine($this->plan($order), $this->xlr)['state']);
    }

    public function test_so_000026_flow_orders_from_the_supplier_while_the_order_is_still_draft(): void
    {
        // SO-000026: accepted quotation → draft order converted before P1, so no requirement row exists yet.
        [$quotation, $line] = $this->quotationWithXlr('2');
        $this->record($quotation, $this->requestSupplier($quotation, $line, $this->mifa), 'available', '2');
        $order = $this->convert($this->issueQuotation($this->owner, $quotation, false))['order'];
        SalesOrderProcurement::query()->delete();

        $plan = $this->plan($order);
        $xlr = $this->planLine($plan, $this->xlr);
        $this->assertSame(SalesOrderProcurementPlanner::REQUIRED, $xlr['state']);
        $this->assertTrue($xlr['derivable']);
        $this->assertSame('MIFA', $xlr['supplier']['name']);
        $this->assertSame(1, $plan['summary']['pending_derivation_count']);
        $this->assertTrue($plan['summary']['ready_for_ordering'], 'The CTA is enabled on the draft order.');
        $this->assertSame([['supplier' => ['id' => $this->mifa->id, 'name' => 'MIFA'], 'requirement_ids' => [], 'line_count' => 1]], $plan['summary']['suppliers']);

        // Confirmation never derives and stays blocked by the shortage.
        $this->assertConfirmationBlocked($order);
        $this->assertSame(0, SalesOrderProcurement::query()->count());

        // "Créer la commande fournisseur": the requirement is derived, then ordered.
        $this->actingAs($this->owner)->post(route('sales.orders.purchase-orders.store', $order), ['supplier_id' => $this->mifa->id])->assertRedirect();

        $requirement = SalesOrderProcurement::query()->sole();
        $this->assertSame('2.0000', $requirement->quantity);
        $this->assertSame(SupplierProcurementStatus::Ordered, $requirement->status);
        $this->assertSame($quotation->id, $requirement->source_quotation_id);
        $this->assertSame($order->id, PurchaseOrder::query()->sole()->lines->sole()->sales_order_id);
        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertSame(SalesOrderProcurementPlanner::ORDERED, $this->planLine($this->plan($order), $this->xlr)['state']);
        $this->assertConfirmationBlocked($order);
    }

    public function test_an_ordered_requirement_alone_does_not_make_the_draft_order_confirmable(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();
        $this->assertConfirmationBlocked($order);

        $this->createPurchaseOrder($this->owner, [$requirement->id]);

        $this->assertSame(SupplierProcurementStatus::Ordered, $requirement->fresh()->status);
        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertConfirmationBlocked($order);
        $this->assertTrue($this->plan($order)['summary']['confirmation_waits_for_receipt']);
    }

    public function test_a_confirmed_order_can_still_order_a_requirement_raised_before_confirmation(): void
    {
        // V1 special order (no quotation): supplier confirmed → order confirmed → purchase order.
        $order = $this->createDraftOrder($this->owner, $this->organization, $this->store, null);
        $line = $this->addCatalogLine($this->owner, $order, $this->xlr, $this->warehouse, ['quantity' => '2']);
        $requirement = app(\App\Actions\Procurement\CreateProcurementAction::class)->execute($this->owner, $order->fresh(), $line->fresh(), $this->mifa, ['quantity' => '2']);
        app(\App\Actions\Procurement\RecordSupplierAvailabilityAction::class)->execute($this->owner, $requirement->fresh(), ['supplier_availability_status' => 'confirmed_available']);
        $this->assertSame('confirmed', app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh())->status->value);

        $purchaseOrder = $this->createPurchaseOrder($this->owner, [$requirement->id]);

        $this->assertSame(PurchaseOrderStatus::Ordered, $purchaseOrder->status);
        $this->assertSame(SupplierProcurementStatus::Ordered, $requirement->fresh()->status);
    }

    public function test_a_cancelled_sales_order_cannot_be_ordered_from_the_supplier(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();
        app(CancelSalesOrderAction::class)->execute($this->owner, $order->fresh(), 'Client a annulé');

        try {
            $this->createPurchaseOrder($this->owner, [$requirement->id]);
            $this->fail('A cancelled order must not be ordered from the supplier.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        try {
            app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->forSalesOrder($this->owner, $order->fresh(), $this->mifa);
        } finally {
            $this->assertSame(0, PurchaseOrder::query()->count());
        }
    }

    // ── 7–15 — purchase orders ──────────────────────────────────────────────

    public function test_a_purchase_order_is_created_from_a_requirement_and_linked_to_the_sales_order_line(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();

        $purchaseOrder = $this->createPurchaseOrder($this->owner, [$requirement->id], ['expected_delivery_date' => '2026-11-05', 'notes' => 'Livraison showroom']);

        $this->assertSame(PurchaseOrderStatus::Ordered, $purchaseOrder->status);
        $this->assertSame('BCF-000001', $purchaseOrder->purchase_order_number);
        $this->assertSame($this->mifa->id, $purchaseOrder->supplier_id);
        $this->assertSame($this->store->id, $purchaseOrder->store_id);
        $this->assertSame('2026-11-05', $purchaseOrder->expected_delivery_date->toDateString());
        $this->assertSame($this->owner->id, $purchaseOrder->created_by_user_id);
        $poLine = $purchaseOrder->lines->sole();
        $this->assertSame($requirement->id, $poLine->sales_order_procurement_id);
        $this->assertSame($requirement->sales_order_line_id, $poLine->sales_order_line_id);
        $this->assertSame($order->id, $poLine->sales_order_id);
        $this->assertSame('2.0000', $poLine->quantity);

        $requirement->refresh();
        $this->assertSame(SupplierProcurementStatus::Ordered, $requirement->status);
        $this->assertSame('2026-11-05', $requirement->expected_at->toDateString());
        foreach (['purchase_order.created', 'purchase_order.ordered'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'auditable_id' => $purchaseOrder->id, 'actor_id' => $this->owner->id]);
        }
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.ordered', 'auditable_id' => $requirement->id]);

        $xlr = $this->planLine($this->plan($order), $this->xlr);
        $this->assertSame(SalesOrderProcurementPlanner::ORDERED, $xlr['state']);
        $this->assertSame([['id' => $purchaseOrder->id, 'number' => 'BCF-000001', 'status' => 'ordered', 'quantity' => '2.0000', 'expected_delivery_date' => '2026-11-05']], $xlr['purchase_orders']);
    }

    public function test_a_second_purchase_order_for_the_same_requirement_is_refused(): void
    {
        [, $requirement] = $this->draftOrderWithRequirement();
        $operation = (string) Str::uuid();
        $first = $this->createPurchaseOrder($this->owner, [$requirement->id], [], $operation);

        // A retried submit returns the same order.
        $this->assertSame($first->id, $this->createPurchaseOrder($this->owner, [$requirement->id], [], $operation)->id);

        try {
            $this->createPurchaseOrder($this->owner, [$requirement->id]);
            $this->fail('A requirement already ordered must not be ordered twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requirement_ids', $exception->errors());
        }
        $this->assertSame(1, PurchaseOrder::query()->count());
        $this->assertSame(1, PurchaseOrderLine::query()->count());
    }

    public function test_a_partial_purchase_order_leaves_only_the_remaining_quantity(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();

        $this->createPurchaseOrder($this->owner, [$requirement->id], ['quantities' => [$requirement->id => '1']]);
        $this->assertSame(SupplierProcurementStatus::SupplierConfirmed, $requirement->fresh()->status);
        $xlr = $this->planLine($this->plan($order), $this->xlr);
        $this->assertSame(SalesOrderProcurementPlanner::REQUIRED, $xlr['state']);
        $this->assertSame('1.0000', $xlr['ordered_quantity']);
        $this->assertSame('1.0000', $xlr['remaining_to_order']);

        // More than the remainder is refused; the default is the remainder.
        try {
            $this->createPurchaseOrder($this->owner, [$requirement->id], ['quantities' => [$requirement->id => '2']]);
            $this->fail('Ordering more than the remainder must be refused.');
        } catch (ValidationException) {
        }
        $second = $this->createPurchaseOrder($this->owner, [$requirement->id]);
        $this->assertSame('1.0000', $second->lines->sole()->quantity);
        $this->assertSame(SupplierProcurementStatus::Ordered, $requirement->fresh()->status);
        $this->assertSame(SalesOrderProcurementPlanner::ORDERED, $this->planLine($this->plan($order), $this->xlr)['state']);

        // A partly ordered requirement is a supplier commitment: the customer order cannot be silently cancelled.
        $this->expectException(ValidationException::class);
        app(CancelSalesOrderAction::class)->execute($this->owner, $order->fresh(), 'Client a annulé');
    }

    public function test_a_requirement_of_another_supplier_is_refused(): void
    {
        [, $requirement] = $this->draftOrderWithRequirement();

        $this->expectException(ValidationException::class);
        app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($this->owner, $this->organization, $this->other, [$requirement->id]);
    }

    public function test_a_supplier_of_another_organization_is_refused(): void
    {
        [, $requirement] = $this->draftOrderWithRequirement();
        $foreignOwner = User::factory()->create();
        $foreignSupplier = $this->supplier($this->createOrganization($foreignOwner), 'MIFA');
        $this->activate($this->owner, $this->organization, $this->store);

        $this->assertHttpStatus(404, fn () => app(CreatePurchaseOrderFromProcurementRequirementsAction::class)
            ->execute($this->owner, $this->organization, $foreignSupplier, [$requirement->id]));
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_a_sales_order_of_another_store_is_refused(): void
    {
        [, $requirement] = $this->draftOrderWithRequirement();
        $otherStore = $this->createStore($this->organization, $this->owner, 'Dépôt', 'DEP');
        $this->activate($this->owner, $this->organization, $otherStore);

        $this->assertHttpStatus(403, fn () => $this->createPurchaseOrder($this->owner->fresh(), [$requirement->id]));
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_a_user_without_procurement_manage_cannot_create_a_purchase_order(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();
        $seller = User::factory()->create();
        $this->addOrganizationMember($this->organization, $seller, ['sales_orders.view', 'procurement.view']);
        $this->addStoreMember($this->store, $seller);
        $this->activate($seller, $this->organization, $this->store);

        $this->assertHttpStatus(403, fn () => $this->createPurchaseOrder($seller, [$requirement->id]));
        $this->actingAs($seller)
            ->post(route('sales.orders.purchase-orders.store', $order), ['supplier_id' => $this->mifa->id])
            ->assertForbidden();

        // The seller still sees the procurement state on the order.
        $this->actingAs($seller)->get(route('sales.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('procurementPlan.summary.state', 'awaiting_procurement')
                ->where('procurementPlan.can.createPurchaseOrder', false));
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_the_sales_order_page_creates_the_purchase_order_for_the_suppliers_open_requirements(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();

        $this->actingAs($this->owner)->get(route('sales.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('procurementPlan.summary.ready_for_ordering', true)
                ->where('procurementPlan.summary.suppliers.0.supplier.name', 'MIFA')
                ->where('procurementPlan.summary.suppliers.0.requirement_ids', [$requirement->id])
                ->where('procurementPlan.can.createPurchaseOrder', true));

        $response = $this->actingAs($this->owner)->post(route('sales.orders.purchase-orders.store', $order), [
            'supplier_id' => $this->mifa->id, 'client_operation_id' => (string) Str::uuid(),
        ]);

        $purchaseOrder = PurchaseOrder::query()->sole();
        $response->assertRedirect(route('procurement.purchase-orders.show', $purchaseOrder));
        $this->actingAs($this->owner)->get(route('procurement.purchase-orders.show', $purchaseOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Procurement/PurchaseOrders/Show')
                ->where('purchaseOrder.purchase_order_number', 'BCF-000001')
                ->where('purchaseOrder.lines.0.sales_order.id', $order->id));
        $this->actingAs($this->owner)->get(route('procurement.purchase-orders.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('sales.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('procurementPlan.summary.state', 'ordered'));
    }

    public function test_cancelling_the_purchase_order_reopens_its_requirements(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();
        $purchaseOrder = $this->createPurchaseOrder($this->owner, [$requirement->id]);

        app(CancelPurchaseOrderAction::class)->execute($this->owner, $purchaseOrder, 'Erreur de fournisseur');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $purchaseOrder->fresh()->status);
        $this->assertSame(SupplierProcurementStatus::SupplierConfirmed, $requirement->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_order.cancelled', 'auditable_id' => $purchaseOrder->id]);
        $this->assertSame(SalesOrderProcurementPlanner::REQUIRED, $this->planLine($this->plan($order), $this->xlr)['state']);
        // It can be ordered again.
        $this->assertSame('BCF-000002', $this->createPurchaseOrder($this->owner, [$requirement->id])->purchase_order_number);
    }

    // ── 16, 17 — MCP read model ─────────────────────────────────────────────

    public function test_the_sales_order_read_model_exposes_a_compact_procurement_summary(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();
        $purchaseOrder = $this->createPurchaseOrder($this->owner, [$requirement->id]);

        $procurement = app(SalesOrderReadService::class)->present($order->fresh(), false)['procurement'];

        $this->assertSame('ordered', $procurement['state']);
        $this->assertTrue($procurement['required']);
        $this->assertTrue($procurement['ordered']);
        $this->assertSame(0, $procurement['unresolved_count']);
        $this->assertSame(1, $procurement['required_line_count']);
        $this->assertSame(1, $procurement['ordered_line_count']);
        $this->assertCount(3, $procurement['lines']);
        $xlr = collect($procurement['lines'])->firstWhere('article', 'Cordial XLR');
        $this->assertSame('procurement_ordered', $xlr['state']);
        $this->assertSame('2.0000', $xlr['required_quantity']);
        $this->assertSame('0.0000', $xlr['remaining_to_order']);
        $this->assertSame('MIFA', $xlr['supplier']['name']);
        $this->assertSame($purchaseOrder->id, $xlr['purchase_orders'][0]['id']);
        $this->assertArrayNotHasKey('requests', $xlr, 'Supplier request histories stay on the procurement pages.');
    }

    // ── 18, 19 — Work attention ─────────────────────────────────────────────

    public function test_attention_appears_while_procurement_is_required_and_closes_once_ordered(): void
    {
        [$order, $requirement] = $this->draftOrderWithRequirement();
        $this->scan();

        $item = AttentionItem::query()->where('sales_order_id', $order->id)->where('type', 'sales_order_procurement_required')->sole();
        $this->assertNotNull($item->active_key);
        $this->assertSame("Approvisionnement requis pour {$order->order_number}", $item->title);

        // A repeated scan keeps the single episode.
        $this->travel(1)->hours();
        $this->scan();
        $this->assertSame(1, AttentionItem::query()->where('type', 'sales_order_procurement_required')->count());

        $this->createPurchaseOrder($this->owner, [$requirement->id]);
        $this->travel(1)->hours();
        $this->scan();
        $this->assertNull($item->fresh()->active_key);
        $this->assertSame('resolved', $item->fresh()->status->value);
    }

    public function test_an_unresolved_line_raises_attention_until_a_supplier_is_confirmed(): void
    {
        [$quotation] = $this->quotationWithXlr('2');
        $order = $this->convert($this->issueQuotation($this->owner, $quotation, true))['order'];
        $this->scan();

        $item = AttentionItem::query()->where('sales_order_id', $order->id)->where('type', 'sales_order_procurement_unresolved')->sole();
        $this->assertNotNull($item->active_key);
        $this->assertSame("Fournisseur requis pour {$order->order_number}", $item->title);
        $this->assertSame(0, AttentionItem::query()->where('type', 'sales_order_procurement_required')->count());

        $this->travel(1)->hours();
        $this->scan();
        $this->assertSame(1, AttentionItem::query()->where('type', 'sales_order_procurement_unresolved')->count(), 'No duplicate episode per scan.');

        // The buyer finds a supplier: the line becomes procurement_required.
        $line = $order->lines()->where('product_variant_id', $this->xlr->id)->sole();
        $requirement = app(\App\Actions\Procurement\CreateProcurementAction::class)->execute($this->owner, $order->fresh(), $line, $this->other, ['quantity' => '2']);
        app(\App\Actions\Procurement\RecordSupplierAvailabilityAction::class)->execute($this->owner, $requirement->fresh(), ['supplier_availability_status' => 'confirmed_available']);
        $this->travel(1)->hours();
        $this->scan();

        $this->assertNull($item->fresh()->active_key);
        $this->assertSame('resolved', $item->fresh()->status->value);
        $this->assertNotNull(AttentionItem::query()->where('sales_order_id', $order->id)->where('type', 'sales_order_procurement_required')->sole()->active_key);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{Quotation, QuotationLine} */
    private function quotationWithXlr(string $quantity): array
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $this->addCatalogQuotationLine($this->owner, $quotation, $this->shure, ['quantity' => '2']);
        $this->addCatalogQuotationLine($this->owner, $quotation, $this->mackie, ['quantity' => '1']);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->xlr, ['quantity' => $quantity]);

        return [$quotation, $line];
    }

    /** Accepted quotation → draft order with its derived XLR requirement (MIFA, 2). @return array{SalesOrder, SalesOrderProcurement} */
    private function draftOrderWithRequirement(): array
    {
        [$quotation, $line] = $this->quotationWithXlr('2');
        $this->record($quotation, $this->requestSupplier($quotation, $line, $this->mifa), 'available', '2');
        $order = $this->convert($this->issueQuotation($this->owner, $quotation, false))['order'];

        return [$order->fresh(), SalesOrderProcurement::query()->sole()];
    }

    private function assertConfirmationBlocked(SalesOrder $order): void
    {
        try {
            app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());
            $this->fail('The order must not be confirmable before the supplier goods are received.');
        } catch (ValidationException) {
            // Blocked: by the stock shortage, or by the requirement not yet received.
        }
        $this->assertSame('draft', $order->fresh()->status->value);
    }

    /** @return array<string, mixed> */
    private function convert(Quotation $issued): array
    {
        return app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $this->acceptQuotation($this->owner, $issued), ['warehouse_id' => $this->warehouse->id]);
    }

    /** @return array{summary: array<string, mixed>, lines: list<array<string, mixed>>} */
    private function plan(SalesOrder $order): array
    {
        return app(SalesOrderProcurementPlanner::class)->plan($order->fresh());
    }

    /** @param array{lines: list<array<string, mixed>>} $plan @return array<string, mixed> */
    private function planLine(array $plan, ProductVariant $variant): array
    {
        return collect($plan['lines'])->firstWhere('product_variant_id', $variant->id);
    }

    /** @param list<int> $requirementIds @param array<string, mixed> $data */
    private function createPurchaseOrder(User $actor, array $requirementIds, array $data = [], ?string $operation = null): PurchaseOrder
    {
        return app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($actor, $this->organization, $this->mifa, $requirementIds, $data, $operation);
    }

    private function requestSupplier(Quotation $quotation, QuotationLine $line, Supplier $supplier): QuotationSupplierAvailabilityRequest
    {
        return app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $supplier->id]);
    }

    private function record(Quotation $quotation, QuotationSupplierAvailabilityRequest $request, string $outcome, ?string $confirmed = null): QuotationSupplierAvailabilityRequest
    {
        return app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => $outcome, 'confirmed_quantity' => $confirmed]);
    }

    private function updateLine(Quotation $quotation, QuotationLine $line, ProductVariant $variant, string $quantity): void
    {
        app(SaveQuotationLineAction::class)->execute($this->owner, $quotation->fresh(), [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0',
        ], $line->fresh());
    }

    private function scan(): void
    {
        app(AttentionScanner::class)->scanOrganization($this->organization->fresh(), CarbonImmutable::now('UTC'));
    }

    private function assertHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function supplier(Organization $organization, string $name): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = $name;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }
}
