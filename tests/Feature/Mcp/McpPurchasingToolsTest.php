<?php

namespace Tests\Feature\Mcp;

use App\Actions\Procurement\CancelPurchaseOrderAction;
use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Enums\InventoryMovementType;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Procurement\PurchasingTools;
use App\Services\Mcp\Sales\SalesOrderTools;
use Carbon\CarbonImmutable;
use Tests\Support\QuotationTestCase;

/** MCP purchasing: purchase orders and goods receipts on the canonical P1/P2 procurement actions. */
class McpPurchasingToolsTest extends QuotationTestCase
{
    private const SCOPES = ['sales_orders.view', 'sales_orders.confirm', 'purchase_orders.view', 'purchase_orders.create', 'procurement_receipts.create'];

    private const TOOLS = ['get_sales_order_procurement', 'search_purchase_orders', 'get_purchase_order', 'preview_purchase_order_creation', 'create_purchase_order', 'preview_procurement_receipt', 'receive_procurement'];

    private User $owner;

    private Organization $organization;

    private Store $storeA;

    private Store $storeB;

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

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->storeA = $this->createStore($this->organization, $this->owner, 'Showroom');
        $this->storeB = $this->createStore($this->organization, $this->owner, 'Store B');
        $this->warehouse = $this->createWarehouse($this->organization, 'Showroom', 'SHW');
        $tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $product = fn (string $name, string $sku) => $this->createProduct($this->organization, $name, $sku, ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->firstOrFail();
        $this->shure = $product('Shure MV7X', 'SHU-MV7X');
        $this->mackie = $product('Mackie ProFX10v3', 'MAC-PFX10');
        $this->xlr = $product('Cordial XLR', 'COR-XLR');
        $this->activate($this->owner, $this->organization, $this->storeA);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->shure, '5.0000');
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->mackie, '3.0000');
        $this->mifa = $this->supplier($this->organization, 'MIFA');
        $this->other = $this->supplier($this->organization, 'Autre fournisseur');
    }

    /* ================================================================ Reads */

    public function test_a_fully_stocked_order_needs_no_procurement(): void
    {
        $order = $this->convertedOrder(withXlr: false);
        $this->connect($this->owner);

        $data = $this->tool('getSalesOrderProcurement', ['sales_order_id' => $order->id])['data'];
        $this->assertSame('covered', $data['state']);
        $this->assertFalse($data['required']);
        $this->assertTrue($data['confirmation_ready']);
        $this->assertSame('confirm_sales_order', $data['next_step']);
        $this->assertSame(['covered_by_stock', 'covered_by_stock'], array_column($data['lines'], 'state'));
    }

    public function test_a_supplier_confirmed_shortage_reads_as_procurement_required(): void
    {
        $order = $this->convertedOrder();
        $this->connect($this->owner);

        $data = $this->tool('getSalesOrderProcurement', ['sales_order_id' => $order->id])['data'];
        $xlr = collect($data['lines'])->firstWhere('article.product_variant_id', $this->xlr->id);
        $this->assertSame('procurement_required', $xlr['state']);
        $this->assertSame('2.0000', $xlr['procurement_required_quantity']);
        $this->assertSame(['id' => $this->mifa->id, 'name' => 'MIFA'], $xlr['supplier']);
        $this->assertSame(['status' => 'confirmed_available', 'source' => 'quotation'], $xlr['availability_evidence']);
        $this->assertNotNull($xlr['source_quotation']);
        $this->assertSame(1, $data['procurement_required_count']);
        $this->assertFalse($data['confirmation_ready']);
        $this->assertSame('create_purchase_order', $data['next_step']);
        $this->assertSame('procurement_not_received', $data['confirmation_blockers'][0]['code']);

        // get_sales_order carries enough to continue the flow.
        $procurement = $this->tool('getSalesOrder', ['sales_order_id' => $order->id], SalesOrderTools::class)['data']['procurement'];
        $this->assertSame('create_purchase_order', $procurement['next_step']);
        $this->assertSame(1, $procurement['to_order_line_count']);
    }

    /* ================================================================ SO-000026 end to end */

    public function test_so_000026_flow_purchase_order_receipt_and_confirmation_entirely_through_mcp(): void
    {
        $order = $this->convertedOrder();
        $this->connect($this->owner);

        // A — procurement state.
        $this->assertSame('procurement_required', $this->xlrLine($order)['state']);

        // B — preview the purchase order: nothing is saved.
        $preview = $this->tool('previewPurchaseOrderCreation', ['sales_order_id' => $order->id, 'supplier_id' => $this->mifa->id, 'expected_delivery_date' => '2026-11-02'])['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));
        $this->assertSame('MIFA', $preview['supplier']['name']);
        $this->assertCount(1, $preview['lines']);
        $this->assertSame('2.0000', $preview['lines'][0]['quantity']);
        $this->assertSame('Cordial XLR', $preview['lines'][0]['article']);
        $this->assertSame(0, PurchaseOrder::query()->count());

        // C — create it: ordered, the sales order stays a draft, no stock moves.
        $created = $this->tool('createPurchaseOrder', ['sales_order_id' => $order->id, 'supplier_id' => $this->mifa->id, 'expected_delivery_date' => '2026-11-02',
            'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'po-1']);
        $this->assertTrue($created['ok'], json_encode($created));
        $po = $created['data']['purchase_order'];
        $this->assertSame('BCF-000001', $po['number']);
        $this->assertSame('ordered', $po['status']);
        $this->assertSame([$order->id], array_column($po['sales_orders'], 'id'));
        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertSame('procurement_ordered', $this->xlrLine($order)['state']);
        $this->assertSame('receive_procurement', $created['data']['sales_order_procurement']['next_step']);
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
        $log = AuditLog::query()->where('event', 'purchase_order.created')->sole();
        $this->assertSame('mcp', $log->actor_channel);
        $this->assertSame($this->owner->id, $log->actor_id);

        // D — confirmation is blocked: ordered is not received.
        $confirmation = $this->tool('previewSalesOrderConfirmation', ['sales_order_id' => $order->id], SalesOrderTools::class)['data'];
        $this->assertFalse($confirmation['valid']);
        $this->assertSame('procurement_not_received', $confirmation['issues'][0]['code']);
        $this->assertSame('BCF-000001', $confirmation['issues'][0]['details']['purchase_order']);

        // E — preview the receipt (the single open line is resolved).
        $receipt = $this->tool('previewProcurementReceipt', ['purchase_order_id' => $po['id'], 'quantity_received' => '2', 'warehouse_id' => $this->warehouse->id, 'supplier_delivery_reference' => 'BL-881'])['data'];
        $this->assertTrue($receipt['valid'], json_encode($receipt['issues']));
        $this->assertSame('2.0000', $receipt['before']['line']['remaining_quantity']);
        $this->assertSame('0.0000', $receipt['after']['line']['remaining_quantity']);
        $this->assertSame('received', $receipt['after']['purchase_order_status']);
        $this->assertTrue($receipt['sales_order_effect']['confirmation_ready']);
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count(), 'The preview stocks nothing.');

        // F — receive: stocked and reserved for the order, not confirmed.
        $received = $this->tool('receiveProcurement', ['purchase_order_id' => $po['id'], 'quantity_received' => '2', 'warehouse_id' => $this->warehouse->id, 'supplier_delivery_reference' => 'BL-881',
            'preview_fingerprint' => $receipt['preview_fingerprint'], 'idempotency_key' => 'rcpt-1']);
        $this->assertTrue($received['ok'], json_encode($received));
        $this->assertSame('2.0000', $received['data']['line']['received_quantity']);
        $this->assertSame('0.0000', $received['data']['line']['remaining_quantity']);
        $this->assertSame('2.0000', $received['data']['receipt']['reserved_for_sales_order']);
        $this->assertTrue($received['data']['sales_order_procurement']['confirmation_ready']);
        $this->assertSame('confirm_sales_order', $received['data']['sales_order_procurement']['next_step']);
        $this->assertSame('draft', $order->fresh()->status->value);
        $receiptReservation = InventoryReservation::query()->where('product_variant_id', $this->xlr->id)->sole();
        $this->assertSame('mcp', AuditLog::query()->where('event', 'procurement.received')->sole()->actor_channel);

        // G, H — confirmation is now valid and keeps the receipt reservation.
        $confirmation = $this->tool('previewSalesOrderConfirmation', ['sales_order_id' => $order->id], SalesOrderTools::class)['data'];
        $this->assertTrue($confirmation['valid'], json_encode($confirmation['issues']));
        $confirmed = $this->tool('confirmSalesOrder', ['sales_order_id' => $order->id, 'preview_fingerprint' => $confirmation['preview_fingerprint']], SalesOrderTools::class);
        $this->assertTrue($confirmed['ok'], json_encode($confirmed));
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $xlrLineId = $order->lines()->where('product_variant_id', $this->xlr->id)->value('id');
        $this->assertSame($receiptReservation->id, SalesOrderInventoryAllocation::query()->where('sales_order_line_id', $xlrLineId)->sole()->inventory_reservation_id);
        $this->assertSame(1, InventoryReservation::query()->where('product_variant_id', $this->xlr->id)->count());
    }

    /* ================================================================ Purchase orders */

    public function test_the_preview_shows_a_read_only_supplier_price_hint_only_with_cost_visibility(): void
    {
        // B1 catalog price for MIFA → XLR, and a first purchase order giving the last actual price.
        app(SaveProductVariantSupplierAction::class)->execute($this->owner, $this->organization, ['supplier_id' => $this->mifa->id, 'product_variant_id' => $this->xlr->id, 'purchase_price' => '120.0000']);
        $first = $this->purchaseOrderFor($this->convertedOrder());
        PurchaseOrderLine::query()->where('purchase_order_id', $first->id)->where('product_variant_id', $this->xlr->id)->update(['unit_price' => '118.5000']);
        $order = $this->convertedOrder();
        $arguments = ['sales_order_id' => $order->id, 'supplier_id' => $this->mifa->id];

        // Without a cost or purchase-order read scope: no price data at all.
        $this->connect($this->owner, ['sales_orders.view', 'purchase_orders.create']);
        $restricted = $this->tool('previewPurchaseOrderCreation', $arguments);
        $this->assertTrue($restricted['data']['valid'], json_encode($restricted));
        $this->assertArrayNotHasKey('price_hint', collect($restricted['data']['lines'])->firstWhere('product_variant_id', $this->xlr->id));
        $this->assertStringNotContainsString('120.0000', json_encode($restricted));
        $this->assertStringNotContainsString('118.5000', json_encode($restricted));

        $this->connect($this->owner);
        $preview = $this->tool('previewPurchaseOrderCreation', $arguments)['data'];
        $hint = collect($preview['lines'])->firstWhere('product_variant_id', $this->xlr->id)['price_hint'];
        $this->assertSame(
            ['120.0000', 'MAD', '118.5000', 'MAD', $first->purchase_order_number, '1.5000'],
            [$hint['catalog_price'], $hint['catalog_currency'], $hint['last_purchase_price'], $hint['last_purchase_currency'], $hint['last_purchase_order_number'], $hint['variance']],
        );
        $this->assertStringContainsString('never written into the order', $preview['note']);

        // Creating the order does not write the suggested price into it.
        $created = $this->tool('createPurchaseOrder', [...$arguments, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'po-hint']);
        $this->assertTrue($created['ok'], json_encode($created));
        $line = PurchaseOrderLine::query()->where('purchase_order_id', $created['data']['purchase_order']['id'])->where('product_variant_id', $this->xlr->id)->sole();
        $this->assertNull($line->unit_price, 'The PO keeps the canonical (requirement) price: none was confirmed.');
    }

    public function test_purchase_order_creation_is_idempotent_and_rejects_a_changed_payload(): void
    {
        $order = $this->convertedOrder();
        $this->connect($this->owner);
        $arguments = ['sales_order_id' => $order->id, 'supplier_id' => $this->mifa->id];
        $preview = $this->tool('previewPurchaseOrderCreation', $arguments)['data'];

        $first = $this->tool('createPurchaseOrder', [...$arguments, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'po-same']);
        $replay = $this->tool('createPurchaseOrder', [...$arguments, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'po-same']);
        $this->assertFalse($first['data']['idempotent_replay']);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame($first['data']['purchase_order']['id'], $replay['data']['purchase_order']['id']);

        $conflict = $this->tool('createPurchaseOrder', [...$arguments, 'expected_delivery_date' => '2026-11-10', 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'po-same']);
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $conflict['error']['code']);
        $this->assertSame(1, PurchaseOrder::query()->count());

        // A second purchase order for the same requirement is refused at preview.
        $again = $this->tool('previewPurchaseOrderCreation', $arguments)['data'];
        $this->assertFalse($again['valid']);
        $this->assertSame('no_open_requirement', $again['issues'][0]['code']);
        $this->assertNull($again['preview_fingerprint']);
    }

    public function test_wrong_unknown_or_foreign_suppliers_are_refused(): void
    {
        $order = $this->convertedOrder();
        [, $foreignOrganization] = $this->otherOrganization();
        $foreignSupplier = $this->supplier($foreignOrganization, 'MIFA');
        $this->connect($this->owner);

        $wrong = $this->tool('previewPurchaseOrderCreation', ['sales_order_id' => $order->id, 'supplier_id' => $this->other->id])['data'];
        $this->assertFalse($wrong['valid']);
        $this->assertSame('no_open_requirement', $wrong['issues'][0]['code']);

        $foreign = $this->tool('previewPurchaseOrderCreation', ['sales_order_id' => $order->id, 'supplier_id' => $foreignSupplier->id])['data'];
        $this->assertSame('supplier_not_found', $foreign['issues'][0]['code']);

        // Without a valid preview nothing can be created.
        $forged = $this->tool('createPurchaseOrder', ['sales_order_id' => $order->id, 'supplier_id' => $this->mifa->id, 'preview_fingerprint' => str_repeat('a', 64), 'idempotency_key' => 'po-x']);
        $this->assertSame('MCP_PREVIEW_STALE', $forged['error']['code']);
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_scope_alone_does_not_bypass_the_erp_permission_and_stores_are_isolated(): void
    {
        $order = $this->convertedOrder();
        $orderB = $this->convertedOrder(store: $this->storeB);
        $seller = $this->member([$this->storeA], ['sales_orders.view', 'procurement.view']);

        $this->assertSame(['get_sales_order_procurement', 'search_purchase_orders', 'get_purchase_order'], array_values(array_intersect(self::TOOLS, $this->listTools($seller, self::SCOPES))));
        $this->connect($seller);
        $denied = $this->tool('previewPurchaseOrderCreation', ['sales_order_id' => $order->id, 'supplier_id' => $this->mifa->id]);
        $this->assertSame('MCP_FORBIDDEN', $denied['error']['code']);

        // Another store's order is not found.
        $this->assertSame('MCP_SALES_ORDER_NOT_FOUND', $this->tool('getSalesOrderProcurement', ['sales_order_id' => $orderB->id])['error']['code']);
    }

    public function test_tools_are_listed_only_with_scope_and_permission(): void
    {
        $this->assertSame(self::TOOLS, array_values(array_intersect(self::TOOLS, $this->listTools($this->owner, self::SCOPES))));
        $this->assertSame(['search_purchase_orders', 'get_purchase_order'], array_values(array_intersect(self::TOOLS, $this->listTools($this->owner, ['purchase_orders.view']))));

        $buyer = $this->member([$this->storeA], ['sales_orders.view', 'procurement.view', 'procurement.manage']);
        $listed = $this->listTools($buyer, self::SCOPES);
        $this->assertContains('create_purchase_order', $listed);
        $this->assertNotContains('receive_procurement', $listed, 'No procurement.receive: no receipt tools.');
    }

    public function test_purchase_order_search_and_reads_are_isolated(): void
    {
        $poA = $this->purchaseOrderFor($this->convertedOrder());
        $poB = $this->purchaseOrderFor($this->convertedOrder(store: $this->storeB));
        [$foreignOwner, $foreignOrganization] = $this->otherOrganization();
        $seller = $this->member([$this->storeA], ['procurement.view']);
        $this->connect($seller, ['purchase_orders.view']);

        $items = $this->tool('searchPurchaseOrders')['data']['items'];
        $this->assertSame([$poA->id], array_column($items, 'id'));
        $this->assertSame('2.0000', $items[0]['remaining_quantity']);
        $this->assertSame('MCP_PURCHASE_ORDER_NOT_FOUND', $this->tool('getPurchaseOrder', ['purchase_order_id' => $poB->id])['error']['code']);

        $read = $this->tool('getPurchaseOrder', ['purchase_order_id' => $poA->id])['data'];
        $this->assertSame('ordered', $read['status']);
        $this->assertSame('2.0000', $read['lines'][0]['remaining_quantity']);
        $this->assertSame('available', $read['lines'][0]['supplier_evidence']['status']);
        $this->assertNotEmpty($read['version']);

        $this->connect($foreignOwner, ['purchase_orders.view'], $foreignOrganization);
        $this->assertSame('MCP_PURCHASE_ORDER_NOT_FOUND', $this->tool('getPurchaseOrder', ['purchase_order_id' => $poA->id])['error']['code']);
        $this->assertSame([], $this->tool('searchPurchaseOrders')['data']['items']);
    }

    /* ================================================================ Receipts */

    public function test_partial_receipts_over_receipt_and_remaining_receipt(): void
    {
        $order = $this->convertedOrder();
        $po = $this->purchaseOrderFor($order);
        $this->connect($this->owner);

        $this->receiveViaMcp($po, '1', 'r-1');
        $line = $this->xlrLine($order);
        $this->assertSame('procurement_partially_received', $line['state']);
        $this->assertSame('1.0000', $line['received_quantity']);
        $this->assertSame('1.0000', $line['remaining_to_receive']);
        $read = $this->tool('getSalesOrderProcurement', ['sales_order_id' => $order->id])['data'];
        $this->assertSame(1, $read['partially_received_count']);
        $this->assertFalse($read['confirmation_ready']);

        $over = $this->tool('previewProcurementReceipt', ['purchase_order_id' => $po->id, 'quantity_received' => '2', 'warehouse_id' => $this->warehouse->id])['data'];
        $this->assertFalse($over['valid']);
        $this->assertSame('receipt_quantity_exceeds_remaining', $over['issues'][0]['code']);
        $this->assertSame('1.0000', $over['issues'][0]['details']['remaining']);

        $partial = $this->tool('previewProcurementReceipt', ['purchase_order_id' => $po->id, 'quantity_received' => '1', 'warehouse_id' => $this->warehouse->id])['data'];
        $this->assertTrue($partial['valid']);
        $this->assertSame('1.0000', $partial['before']['line']['remaining_quantity']);
        $this->assertSame('received', $partial['after']['line']['status']);

        $this->receiveViaMcp($po, '1', 'r-2');
        $read = $this->tool('getSalesOrderProcurement', ['sales_order_id' => $order->id])['data'];
        $this->assertSame(1, $read['received_count']);
        $this->assertTrue($read['confirmation_ready']);

        // Received goods are reserved: another order cannot take them.
        $other = $this->createDraftOrder($this->owner, $this->organization, $this->storeA, null);
        $this->addCatalogLine($this->owner, $other, $this->xlr, $this->warehouse, ['quantity' => '1']);
        $otherLine = collect($this->tool('getSalesOrderProcurement', ['sales_order_id' => $other->id])['data']['lines'])->sole();
        $this->assertSame('0.0000', $otherLine['stock_covered_quantity']);
        $this->assertSame('unresolved', $otherLine['state']);
    }

    public function test_receipt_retry_is_idempotent_and_a_stale_preview_is_rejected(): void
    {
        $po = $this->purchaseOrderFor($this->convertedOrder());
        $this->connect($this->owner);
        $arguments = ['purchase_order_id' => $po->id, 'quantity_received' => '1', 'warehouse_id' => $this->warehouse->id];
        $stale = $this->tool('previewProcurementReceipt', $arguments)['data'];
        $fresh = $this->tool('previewProcurementReceipt', $arguments)['data'];

        $first = $this->tool('receiveProcurement', [...$arguments, 'preview_fingerprint' => $fresh['preview_fingerprint'], 'idempotency_key' => 'rcpt-same']);
        $replay = $this->tool('receiveProcurement', [...$arguments, 'preview_fingerprint' => $fresh['preview_fingerprint'], 'idempotency_key' => 'rcpt-same']);
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());

        // The other preview was made before that receipt.
        $late = $this->tool('receiveProcurement', [...$arguments, 'preview_fingerprint' => $stale['preview_fingerprint'], 'idempotency_key' => 'rcpt-late']);
        $this->assertSame('MCP_PREVIEW_STALE', $late['error']['code']);
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
    }

    public function test_cancelled_purchase_orders_bad_warehouses_and_foreign_lines_are_refused(): void
    {
        $po = $this->purchaseOrderFor($this->convertedOrder());
        $second = $this->purchaseOrderFor($this->convertedOrder());
        $inactive = $this->createWarehouse($this->organization, 'Fermé', 'CLO');
        $inactive->status = 'inactive';
        $inactive->save();
        [$foreignOwner, $foreignOrganization] = $this->otherOrganization();
        $foreignWarehouse = $this->createWarehouse($foreignOrganization, 'Ailleurs', 'AIL');
        $this->connect($this->owner);

        foreach ([$inactive->id, $foreignWarehouse->id] as $warehouseId) {
            $issue = $this->tool('previewProcurementReceipt', ['purchase_order_id' => $po->id, 'quantity_received' => '1', 'warehouse_id' => $warehouseId])['data']['issues'][0];
            $this->assertSame('warehouse_invalid', $issue['code']);
        }

        $foreignLine = $this->tool('previewProcurementReceipt', ['purchase_order_id' => $po->id, 'purchase_order_line_id' => $second->lines->sole()->id, 'quantity_received' => '1', 'warehouse_id' => $this->warehouse->id]);
        $this->assertSame('MCP_VALIDATION_FAILED', $foreignLine['error']['code']);

        app(CancelPurchaseOrderAction::class)->execute($this->owner, $po, 'Erreur');
        $cancelled = $this->tool('previewProcurementReceipt', ['purchase_order_id' => $po->id, 'purchase_order_line_id' => $po->lines->sole()->id, 'quantity_received' => '1', 'warehouse_id' => $this->warehouse->id])['data'];
        $this->assertSame('purchase_order_cancelled', $cancelled['issues'][0]['code']);

        // Another organization cannot see the purchase order at all.
        $this->connect($foreignOwner, self::SCOPES, $foreignOrganization);
        $this->assertSame('MCP_PURCHASE_ORDER_NOT_FOUND', $this->tool('previewProcurementReceipt', ['purchase_order_id' => $second->id, 'quantity_received' => '1', 'warehouse_id' => $foreignWarehouse->id])['error']['code']);
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', InventoryMovementType::SupplierReceipt->value)->count());
    }

    /* ================================================================ Helpers */

    /** Accepted quotation (2 × Shure, 1 × Mackie[, 2 × XLR confirmed by MIFA]) converted to a draft order, with the web actions. */
    private function convertedOrder(bool $withXlr = true, ?Store $store = null): SalesOrder
    {
        app()->forgetScopedInstances();
        $store ??= $this->storeA;
        $this->activate($this->owner, $this->organization, $store);
        $quotation = $this->createQuotation($this->owner, $this->organization, $store);
        $this->addCatalogQuotationLine($this->owner, $quotation, $this->shure, ['quantity' => '2']);
        $this->addCatalogQuotationLine($this->owner, $quotation, $this->mackie, ['quantity' => '1']);
        if ($withXlr) {
            $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->xlr, ['quantity' => '2']);
            $request = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $this->mifa->id]);
            app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => '2']);
        }
        $issued = $this->issueQuotation($this->owner, $quotation->fresh(), false);
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $this->acceptQuotation($this->owner, $issued), ['warehouse_id' => $this->warehouse->id])['order'];
        $this->activate($this->owner, $this->organization, $this->storeA);

        return $order->fresh();
    }

    /** Preview + create through MCP, then unbind. */
    private function purchaseOrderFor(SalesOrder $order): PurchaseOrder
    {
        $this->connect($this->owner);
        $arguments = ['sales_order_id' => $order->id, 'supplier_id' => $this->mifa->id];
        $preview = $this->tool('previewPurchaseOrderCreation', $arguments)['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));
        $created = $this->tool('createPurchaseOrder', [...$arguments, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'po-'.$order->id]);
        app()->forgetScopedInstances();

        return PurchaseOrder::query()->with('lines')->findOrFail($created['data']['purchase_order']['id']);
    }

    private function receiveViaMcp(PurchaseOrder $po, string $quantity, string $key): array
    {
        $arguments = ['purchase_order_id' => $po->id, 'quantity_received' => $quantity, 'warehouse_id' => $this->warehouse->id];
        $preview = $this->tool('previewProcurementReceipt', $arguments)['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));
        $received = $this->tool('receiveProcurement', [...$arguments, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => $key]);
        $this->assertTrue($received['ok'], json_encode($received));

        return $received['data'];
    }

    /** @return array<string, mixed> */
    private function xlrLine(SalesOrder $order): array
    {
        return collect($this->tool('getSalesOrderProcurement', ['sales_order_id' => $order->id])['data']['lines'])->firstWhere('article.product_variant_id', $this->xlr->id);
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES, ?Organization $organization = null): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $organization ?? $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @return array<string, mixed> */
    private function tool(string $method, array $arguments = [], string $class = PurchasingTools::class): array
    {
        return app($class)->{$method}(...$arguments)->structuredContent;
    }

    /** @param list<Store> $stores @param list<string> $permissions */
    private function member(array $stores, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }

        return $user;
    }

    /** @return array{User, Organization} */
    private function otherOrganization(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Other');
        $organization->settings = ['allow_mcp_connections' => true];
        $organization->save();

        return [$owner, $organization->refresh()];
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

    /** @param list<string> $scopes @return list<string> */
    private function listTools(User $user, array $scopes): array
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        $headers = ['Authorization' => 'Bearer '.$issued['secret'], 'Accept' => 'application/json, text/event-stream'];
        $session = $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->headers->get('Mcp-Session-Id');
        $headers += ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => '2025-11-25'];
        $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $names = array_column($this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass])->json('result.tools') ?? [], 'name');
        $this->flushHeaders();

        return $names;
    }
}
