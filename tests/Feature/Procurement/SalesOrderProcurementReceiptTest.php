<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\CancelPurchaseOrderAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\AttentionItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ProcurementReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Attention\AttentionScanner;
use App\Services\InventoryReservationManager;
use App\Services\Mcp\Sales\SalesOrderReadService;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

/**
 * Post-quotation procurement P2: supplier goods received against a purchase
 * order are stocked and reserved for the Sales Order line, the requirement and
 * the purchase order follow the received quantities, and confirmation adopts
 * the receipt reservation instead of rebuilding it.
 */
class SalesOrderProcurementReceiptTest extends QuotationTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private Warehouse $depot;

    private ProductVariant $shure;

    private ProductVariant $mackie;

    private ProductVariant $xlr;

    private Supplier $mifa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00'));

        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization, 'Showroom', 'SHW');
        $this->depot = $this->createWarehouse($this->organization, 'Dépôt', 'DEP');
        $tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $product = fn (string $name, string $sku) => $this->createProduct($this->organization, $name, $sku, ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->firstOrFail();
        $this->shure = $product('Shure MV7X', 'SHU-MV7X');
        $this->mackie = $product('Mackie ProFX10v3', 'MAC-PFX10');
        $this->xlr = $product('Cordial XLR', 'COR-XLR');
        $this->activate($this->owner, $this->organization, $this->store);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->shure, '5.0000');
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->mackie, '3.0000');

        $this->mifa = new Supplier;
        $this->mifa->organization_id = $this->organization->id;
        $this->mifa->name = 'MIFA';
        $this->mifa->active = true;
        $this->mifa->save();
    }

    // ── 1–3, 8 — full receipt ───────────────────────────────────────────────

    public function test_a_full_receipt_stocks_the_goods_and_reserves_them_for_the_order(): void
    {
        [$order, $requirement, $purchaseOrder, $poLine] = $this->orderedFlow();

        $this->receive($poLine, '2', ['supplier_delivery_reference' => 'BL-MIFA-881']);

        $balance = $this->stockBalance($this->warehouse, $this->xlr);
        $this->assertSame('2.0000', (string) $balance->on_hand);
        $this->assertSame('2.0000', (string) $balance->reserved, 'Received for the order: reserved, never free.');
        $movement = InventoryMovement::query()->where('product_variant_id', $this->xlr->id)->where('movement_type', InventoryMovementType::SupplierReceipt->value)->sole();
        $this->assertSame('2.0000', (string) $movement->quantity);

        $receipt = ProcurementReceipt::query()->sole();
        $this->assertSame($poLine->id, $receipt->purchase_order_line_id);
        $this->assertSame($movement->id, $receipt->inventory_movement_id);
        $this->assertSame('BL-MIFA-881', $receipt->supplier_delivery_reference);
        $reservation = InventoryReservation::query()->findOrFail($receipt->inventory_reservation_id);
        $this->assertSame(InventoryReservationStatus::Active, $reservation->status);
        $this->assertSame(SalesOrderProcurement::class, $reservation->reference_type);
        $this->assertSame($requirement->id, $reservation->reference_id);

        $requirement->refresh();
        $this->assertSame(SupplierProcurementStatus::Received, $requirement->status);
        $this->assertSame('2.0000', (string) $requirement->received_quantity);
        $this->assertSame('2.0000', (string) $poLine->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::Received, $purchaseOrder->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'procurement.received', 'auditable_id' => $requirement->id, 'actor_id' => $this->owner->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_order.received', 'auditable_id' => $purchaseOrder->id]);
        $this->assertSame('draft', $order->fresh()->status->value);
    }

    public function test_goods_received_for_one_order_are_not_free_for_another(): void
    {
        [, , , $poLine] = $this->orderedFlow();
        $this->receive($poLine, '2');

        $other = $this->createDraftOrder($this->owner, $this->organization, $this->store, null);
        $this->addCatalogLine($this->owner, $other, $this->xlr, $this->warehouse, ['quantity' => '1']);

        $line = collect($this->plan($other)['lines'])->firstWhere('product_variant_id', $this->xlr->id);
        $this->assertSame('0.0000', $line['internal_available_unreserved']);
        $this->expectException(ValidationException::class);
        app(ConfirmSalesOrderAction::class)->execute($this->owner, $other->fresh());
    }

    // ── 5–7 — partial receipts ──────────────────────────────────────────────

    public function test_partial_receipts_advance_quantities_and_keep_confirmation_blocked_until_complete(): void
    {
        [$order, $requirement, $purchaseOrder, $poLine] = $this->orderedFlow();

        $this->receive($poLine, '1');

        $this->assertSame(SupplierProcurementStatus::PartiallyReceived, $requirement->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $purchaseOrder->fresh()->status);
        $xlr = $this->planLine($order, $this->xlr);
        $this->assertSame(SalesOrderProcurementPlanner::PARTIALLY_RECEIVED, $xlr['state']);
        $this->assertSame('2.0000', $xlr['procurement_ordered']);
        $this->assertSame('1.0000', $xlr['procurement_received']);
        $this->assertSame('1.0000', $xlr['procurement_remaining']);
        $this->assertFalse($xlr['confirmation_ready']);

        $blockers = app(SalesOrderProcurementPlanner::class)->confirmationBlockers($order->fresh());
        $this->assertSame('procurement_not_received', $blockers[0]['code']);
        $this->assertSame([
            'sales_order_id' => $order->id, 'sales_order_line_id' => $xlr['line_id'], 'required' => '2.0000', 'ordered' => '2.0000',
            'received' => '1.0000', 'remaining' => '1.0000', 'purchase_order' => 'BCF-000001',
        ], $blockers[0]['details']);
        $this->assertConfirmationRejected($order, 'procurement');

        $this->receive($poLine, '1');

        $this->assertSame(SupplierProcurementStatus::Received, $requirement->fresh()->status);
        $this->assertSame('2.0000', (string) $requirement->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::Received, $purchaseOrder->fresh()->status);
        $this->assertSame(2, ProcurementReceipt::query()->count());
        // One reservation per requirement and warehouse, increased by the second receipt.
        $this->assertSame(1, InventoryReservation::query()->where('product_variant_id', $this->xlr->id)->count());
        $this->assertSame('2.0000', (string) InventoryReservation::query()->where('product_variant_id', $this->xlr->id)->sole()->quantity);
        $this->assertTrue($this->plan($order)['summary']['confirmation_ready']);
    }

    // ── 9–11, 14, 25 — SO-000026 end to end ─────────────────────────────────

    public function test_so_000026_flow_confirms_after_receipt_and_preserves_the_receipt_reservation(): void
    {
        [$order, , , $poLine] = $this->orderedFlow();
        $this->assertConfirmationRejected($order, 'procurement');

        $this->receive($poLine, '2');
        $receiptReservation = InventoryReservation::query()->where('product_variant_id', $this->xlr->id)->sole();
        $summary = $this->plan($order)['summary'];
        $this->assertSame('received', $summary['state']);
        $this->assertTrue($summary['confirmation_ready']);
        $this->assertSame([], app(SalesOrderProcurementPlanner::class)->confirmationBlockers($order->fresh()));

        $confirmed = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());

        $this->assertSame('confirmed', $confirmed->status->value);
        $xlrLine = $confirmed->lines()->where('product_variant_id', $this->xlr->id)->sole();
        $allocation = SalesOrderInventoryAllocation::query()->where('sales_order_line_id', $xlrLine->id)->sole();
        $this->assertSame($receiptReservation->id, $allocation->inventory_reservation_id, 'The receipt reservation is adopted, not rebuilt.');
        $this->assertSame('2.0000', (string) $allocation->quantity);
        $this->assertSame(1, InventoryReservation::query()->where('product_variant_id', $this->xlr->id)->count(), 'No duplicate reservation.');
        $receiptReservation->refresh();
        $this->assertSame(InventoryReservationStatus::Active, $receiptReservation->status);
        $this->assertSame('2.0000', (string) $receiptReservation->quantity);
        $this->assertSame(SalesOrderInventoryAllocation::class, $receiptReservation->reference_type);
        $this->assertSame($allocation->id, ProcurementReceipt::query()->sole()->sales_order_inventory_allocation_id);
        $this->assertSame('2.0000', (string) $this->stockBalance($this->warehouse, $this->xlr)->reserved);
        // Stocked lines are reserved as usual.
        $this->assertSame('2.0000', (string) $this->stockBalance($this->warehouse, $this->shure)->reserved);
        $this->assertSame('1.0000', (string) $this->stockBalance($this->warehouse, $this->mackie)->reserved);
        // Confirmation creates no payment and no invoice.
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Invoice::query()->count());

        $procurement = app(SalesOrderReadService::class)->present($confirmed->fresh(), false)['procurement'];
        $this->assertSame('received', $procurement['state']);
        $this->assertSame('2.0000', collect($procurement['lines'])->firstWhere('article', 'Cordial XLR')['already_reserved_for_this_order']);
    }

    public function test_company_stock_at_the_receipt_warehouse_joins_the_adopted_reservation(): void
    {
        // 4 XLR sold, 1 in company stock at the showroom: 3 ordered from MIFA and received there.
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->xlr, '1.0000');
        [$order, $requirement, , $poLine] = $this->orderedFlow('4');
        $this->assertSame('3.0000', (string) $requirement->quantity);
        $this->receive($poLine, '3');
        $line = $order->lines()->where('product_variant_id', $this->xlr->id)->sole();

        app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());

        $allocation = SalesOrderInventoryAllocation::query()->where('sales_order_line_id', $line->id)->sole();
        $this->assertSame('4.0000', (string) $allocation->quantity);
        $reservation = InventoryReservation::query()->where('product_variant_id', $this->xlr->id)->sole();
        $this->assertSame($allocation->inventory_reservation_id, $reservation->id);
        $this->assertSame('4.0000', (string) $reservation->quantity);
        $this->assertSame('4.0000', (string) $this->stockBalance($this->warehouse, $this->xlr)->reserved);
    }

    public function test_a_conflicting_reservation_on_a_draft_allocation_is_rejected(): void
    {
        [$order, , , $poLine] = $this->orderedFlow();
        $this->receive($poLine, '2');
        $shureAllocation = SalesOrderInventoryAllocation::query()
            ->where('sales_order_line_id', $order->lines()->where('product_variant_id', $this->shure->id)->value('id'))->firstOrFail();
        $foreign = app(InventoryReservationManager::class)->reserve($this->owner, $this->organization, $this->warehouse, $this->shure, '1', 'manual', 999, 'Autre');
        $shureAllocation->inventory_reservation_id = $foreign->id;
        $shureAllocation->save();

        try {
            app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());
            $this->fail('A reservation belonging to something else must not be adopted.');
        } catch (ValidationException $exception) {
            $this->assertSame('A draft allocation is already linked to a reservation.', $exception->errors()['lines'][0]);
        }
        $this->assertSame('draft', $order->fresh()->status->value);
    }

    // ── 12–14, 20 — integrity, idempotency, staleness ───────────────────────

    public function test_a_receipt_above_the_remaining_quantity_is_rejected(): void
    {
        [, , , $poLine] = $this->orderedFlow();
        $this->receive($poLine, '1');

        $this->expectException(ValidationException::class);
        try {
            $this->receive($poLine, '2');
        } finally {
            $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
            $this->assertSame('1.0000', (string) $poLine->fresh()->received_quantity);
        }
    }

    public function test_a_retried_receipt_does_not_stock_twice_and_a_stale_one_is_rejected(): void
    {
        [, $requirement, , $poLine] = $this->orderedFlow();
        $operation = (string) Str::uuid();

        $this->receive($poLine, '1', ['client_operation_id' => $operation, 'expected_received_quantity' => '0']);
        $this->receive($poLine, '1', ['client_operation_id' => $operation, 'expected_received_quantity' => '0']);

        $this->assertSame('1.0000', (string) $requirement->fresh()->received_quantity);
        $this->assertSame('1.0000', (string) $this->stockBalance($this->warehouse, $this->xlr)->on_hand);
        $this->assertSame(1, ProcurementReceipt::query()->count());

        // Another submit prepared before that receipt (it still believes 0 received).
        try {
            $this->receive($poLine, '1', ['client_operation_id' => (string) Str::uuid(), 'expected_received_quantity' => '0']);
            $this->fail('A receipt prepared on stale figures must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->assertSame('1.0000', (string) $this->stockBalance($this->warehouse, $this->xlr)->on_hand);
    }

    // ── 15, 16 — purchase order lifecycle ───────────────────────────────────

    public function test_a_cancelled_purchase_order_cannot_be_received(): void
    {
        [, , $purchaseOrder, $poLine] = $this->orderedFlow();
        app(CancelPurchaseOrderAction::class)->execute($this->owner, $purchaseOrder, 'Erreur');

        $this->expectException(ValidationException::class);
        try {
            $this->receive($poLine, '1');
        } finally {
            $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
        }
    }

    public function test_a_purchase_order_with_received_goods_cannot_be_cancelled(): void
    {
        [, , $purchaseOrder, $poLine] = $this->orderedFlow();
        $this->receive($poLine, '1');

        foreach (['partial', 'full'] as $step) {
            try {
                app(CancelPurchaseOrderAction::class)->execute($this->owner, $purchaseOrder->fresh(), 'Erreur');
                $this->fail("A purchase order with received goods ({$step}) must not be cancelled.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('purchase_order', $exception->errors());
            }
            if ($step === 'partial') {
                $this->receive($poLine, '1');
            }
        }
        $this->assertSame(PurchaseOrderStatus::Received, $purchaseOrder->fresh()->status);
    }

    // ── 17, 18, 19 — permissions and isolation ──────────────────────────────

    public function test_receipt_is_isolated_by_organization_permission_warehouse_and_line(): void
    {
        [, $requirement, , $poLine] = $this->orderedFlow();

        // Another organization's user.
        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner);
        $foreignStore = $this->createStore($foreignOrganization, $foreignOwner);
        $foreignWarehouse = $this->createWarehouse($foreignOrganization, 'Ailleurs', 'AIL');
        $this->activate($foreignOwner, $foreignOrganization, $foreignStore);
        $this->assertHttpStatus(403, fn () => app(ReceiveProcurementAction::class)->execute($foreignOwner, $requirement->fresh(), $this->warehouse, ['quantity' => '1']));

        // Another organization's warehouse.
        $this->assertHttpStatus(404, fn () => $this->receive($poLine, '1', [], $foreignWarehouse));

        // No procurement.receive permission.
        $seller = User::factory()->create();
        $this->addOrganizationMember($this->organization, $seller, ['sales_orders.view', 'procurement.view', 'procurement.manage']);
        $this->addStoreMember($this->store, $seller);
        $this->activate($seller, $this->organization, $this->store);
        $this->assertHttpStatus(403, fn () => app(ReceiveProcurementAction::class)->execute($seller, $requirement->fresh(), $this->warehouse, ['quantity' => '1', 'purchase_order_line_id' => $poLine->id]));

        // Another store than the requirement's.
        $otherStore = $this->createStore($this->organization, $this->owner, 'Dépôt');
        $this->activate($this->owner, $this->organization, $otherStore);
        $this->assertHttpStatus(403, fn () => $this->receive($poLine, '1', [], null, $this->owner->fresh()));
        $this->activate($this->owner, $this->organization, $this->store);

        // A purchase order line of another requirement.
        try {
            app(ReceiveProcurementAction::class)->execute($this->owner->fresh(), $requirement->fresh(), $this->warehouse, ['quantity' => '1', 'purchase_order_line_id' => $poLine->id + 999]);
            $this->fail('A foreign purchase order line must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_order_line_id', $exception->errors());
        }

        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
    }

    // ── 19 — pages ──────────────────────────────────────────────────────────

    public function test_the_purchase_order_page_receives_and_the_order_card_shows_the_figures(): void
    {
        [$order, $requirement, $purchaseOrder, $poLine] = $this->orderedFlow();

        $this->actingAs($this->owner)->post(route('procurement.purchase-orders.lines.receive', [$purchaseOrder, $poLine->id]), [
            'quantity' => '1', 'warehouse_id' => $this->depot->id, 'received_on' => '2026-10-28', 'supplier_delivery_reference' => 'BL-1',
            'client_operation_id' => (string) Str::uuid(), 'expected_received_quantity' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1.0000', (string) $this->stockBalance($this->depot, $this->xlr)->reserved);

        $this->actingAs($this->owner)->get(route('procurement.purchase-orders.show', $purchaseOrder))
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.status', 'partially_received')
                ->where('purchaseOrder.lines.0.received_quantity', '1.0000')
                ->where('purchaseOrder.lines.0.remaining_quantity', '1.0000')
                ->where('purchaseOrder.lines.0.receipts.0.warehouse.name', 'Dépôt')
                ->where('purchaseOrder.lines.0.source_quotation.id', $requirement->source_quotation_id)
                ->where('purchaseOrder.lines.0.supplier_evidence.status', 'available')
                ->where('can.receive', true)
                ->where('can.cancel', false));

        $this->actingAs($this->owner)->get(route('sales.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('procurementPlan.summary.state', 'partially_received')
                ->where('procurementPlan.summary.confirmation_ready', false)
                ->where('procurementPlan.lines.2.procurement_ordered', '2.0000')
                ->where('procurementPlan.lines.2.procurement_received', '1.0000')
                ->where('procurementPlan.lines.2.procurement_remaining', '1.0000'));
    }

    // ── 20–22 — attention ───────────────────────────────────────────────────

    public function test_awaiting_receipt_attention_escalates_when_overdue_and_closes_once_received(): void
    {
        [$order, , , $poLine] = $this->orderedFlow('2', '2026-11-02');
        $this->scan();

        $item = AttentionItem::query()->where('sales_order_id', $order->id)->where('type', 'sales_order_procurement_awaiting_receipt')->sole();
        $this->assertSame('warning', $item->severity->value);
        $this->assertSame("En attente de réception fournisseur pour {$order->order_number}", $item->title);

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
        $this->scan();
        $item->refresh();
        $this->assertSame('critical', $item->severity->value, 'Past the expected delivery date.');
        $this->assertNotNull($item->critical_since);
        $this->assertSame(1, AttentionItem::query()->where('type', 'sales_order_procurement_awaiting_receipt')->count());

        $this->receive($poLine, '1');
        $this->travel(1)->hours();
        $this->scan();
        $this->assertNotNull($item->fresh()->active_key, 'Still one unit expected.');

        $this->receive($poLine, '1');
        $this->travel(1)->hours();
        $this->scan();
        $this->assertNull($item->fresh()->active_key);
        $this->assertSame('resolved', $item->fresh()->status->value);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Accepted quotation (2 × Shure, 1 × Mackie, N × XLR confirmed by MIFA) → draft
     * order → requirement → purchase order.
     *
     * @return array{SalesOrder, SalesOrderProcurement, PurchaseOrder, PurchaseOrderLine}
     */
    private function orderedFlow(string $xlr = '2', ?string $expected = null): array
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $this->addCatalogQuotationLine($this->owner, $quotation, $this->shure, ['quantity' => '2']);
        $this->addCatalogQuotationLine($this->owner, $quotation, $this->mackie, ['quantity' => '1']);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->xlr, ['quantity' => $xlr]);
        $request = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $this->mifa->id]);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => $xlr]);
        $issued = $this->issueQuotation($this->owner, $quotation->fresh(), false);
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $this->acceptQuotation($this->owner, $issued), ['warehouse_id' => $this->warehouse->id])['order'];
        $requirement = SalesOrderProcurement::query()->where('sales_order_id', $order->id)->sole();
        $purchaseOrder = app(CreatePurchaseOrderFromProcurementRequirementsAction::class)
            ->execute($this->owner, $this->organization, $this->mifa, [$requirement->id], array_filter(['expected_delivery_date' => $expected]));

        return [$order->fresh(), $requirement->fresh(), $purchaseOrder->fresh(), $purchaseOrder->lines->sole()];
    }

    /** @param array<string, mixed> $data */
    private function receive(PurchaseOrderLine $poLine, string $quantity, array $data = [], ?Warehouse $warehouse = null, ?User $actor = null): SalesOrderProcurement
    {
        return app(ReceiveProcurementAction::class)->execute($actor ?? $this->owner, SalesOrderProcurement::query()->findOrFail($poLine->sales_order_procurement_id),
            $warehouse ?? $this->warehouse, [...$data, 'quantity' => $quantity, 'purchase_order_line_id' => $poLine->id]);
    }

    /** @return array{summary: array<string, mixed>, lines: list<array<string, mixed>>} */
    private function plan(SalesOrder $order): array
    {
        return app(SalesOrderProcurementPlanner::class)->plan($order->fresh());
    }

    /** @return array<string, mixed> */
    private function planLine(SalesOrder $order, ProductVariant $variant): array
    {
        return collect($this->plan($order)['lines'])->firstWhere('product_variant_id', $variant->id);
    }

    private function stockBalance(Warehouse $warehouse, ProductVariant $variant): InventoryBalance
    {
        return InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->firstOrFail();
    }

    private function assertConfirmationRejected(SalesOrder $order, string $key): void
    {
        try {
            app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());
            $this->fail('The order must not be confirmable yet.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
        $this->assertSame('draft', $order->fresh()->status->value);
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
}
