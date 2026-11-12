<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Procurement\CreateProcurementAction;
use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Procurement\ReceiveProcurementAction;
use App\Models\InventoryBalance;
use App\Models\ProcurementReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\Supplier;
use App\Services\Procurement\SupplierCatalogQuery;
use App\Services\Sandbox\SandboxInventoryGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

/** Phase 6: Test procurement / supplier purchasing on the Test ledger, Production untouched. */
class SandboxProcurementTest extends QuotationTestCase
{
    public function test_test_procurement_flow_increases_test_stock_only_and_production_flow_is_unchanged(): void
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $supplier = $this->supplier($org);
        $this->actingAs($owner);
        $before = $this->productionProcurement();

        $this->enterTest();
        // Test quotation for 3 units with NO Test stock -> supplier sourcing.
        $quote = $this->createQuotation($owner, $org, $store);
        $line = $this->addCatalogQuotationLine($owner, $quote, $variant, ['quantity' => '3']);
        $this->post("/quotations/{$quote->id}/lines/{$line->id}/supplier-requests", ['supplier_id' => $supplier->id])->assertRedirect()->assertSessionHasNoErrors();
        $request = QuotationSupplierAvailabilityRequest::query()->sole();
        $this->assertSame('sandbox_quotation_supplier_availability_requests', $request->getTable());
        $this->patch("/quotations/{$quote->id}/supplier-requests/{$request->id}", ['outcome' => 'available', 'confirmed_quantity' => '3'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/quotations/{$quote->id}/issue")->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/quotations/{$quote->id}/accept")->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/quotations/{$quote->id}/conversion", ['warehouse_id' => $warehouse->id])->assertRedirect();
        $order = SalesOrder::query()->findOrFail(Quotation::findOrFail($quote->id)->converted_sales_order_id);

        // Requirement derived on the Test ledger, with a Test reference.
        $requirement = SalesOrderProcurement::query()->sole();
        $this->assertSame('sandbox_sales_order_procurements', $requirement->getTable());
        $this->assertSame('TEST-PR-000001', $requirement->procurement_number);
        $this->assertSame($order->id, $requirement->sales_order_id);
        $this->get('/procurement')->assertOk();
        // Not confirmable until the goods are received.
        $this->post("/sales/orders/{$order->id}/confirm")->assertSessionHasErrors();

        // Test purchase order.
        $this->post("/sales/orders/{$order->id}/purchase-orders", ['supplier_id' => $supplier->id])->assertRedirect()->assertSessionHasNoErrors();
        $purchaseOrder = PurchaseOrder::query()->sole();
        $this->assertSame('sandbox_purchase_orders', $purchaseOrder->getTable());
        $this->assertSame('TEST-PO-000001', $purchaseOrder->purchase_order_number);
        $poLine = PurchaseOrderLine::query()->sole();
        $this->get('/procurement/purchase-orders')->assertOk();
        $this->get("/procurement/purchase-orders/{$purchaseOrder->id}")->assertOk();
        $stockBefore = $this->productionStock();

        // Receive: Test stock rises, Production stock and ledger do not move.
        $this->post("/procurement/purchase-orders/{$purchaseOrder->id}/lines/{$poLine->id}/receive", ['quantity' => '3', 'warehouse_id' => $warehouse->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $receipt = ProcurementReceipt::query()->sole();
        $this->assertSame('sandbox_procurement_receipts', $receipt->getTable());
        $this->assertSame('3.0000', InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('on_hand'));
        $this->assertSame('sandbox_inventory_movements', $receipt->inventoryMovement->getTable());
        $this->assertSame('supplier_receipt', $receipt->inventoryMovement->movement_type->value);
        $this->assertSame('received', $purchaseOrder->fresh()->status->value);
        $this->assertSame(0, DB::table('transfer_requests')->count()); // no cross-dock transfer request in Test

        // The Test order can now be confirmed on the received (Test) stock.
        $this->post("/sales/orders/{$order->id}/confirm")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $order->fresh()->status->value);

        // Raw SQL on Production procurement tables is refused in Test Mode.
        try {
            DB::table('purchase_orders')->count();
            $this->fail('Production purchase orders readable in Test Mode.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('purchase_orders', $e->getMessage());
        }

        $this->leaveTest();
        $this->assertEquals($before, $this->productionProcurement());
        $this->assertEquals($stockBefore, $this->productionStock());
        $this->assertNull(PurchaseOrder::query()->first());
        $this->assertNull(SalesOrderProcurement::query()->first());
        $this->get('/procurement/purchase-orders')->assertOk()->assertDontSee('TEST-PO-000001');

        // Production procurement flow is unchanged: official numbers, Production stock only.
        $prodOrder = $this->createDraftOrder($owner, $org, $store);
        $prodLine = $this->addCatalogLine($owner, $prodOrder, $variant, $warehouse, ['quantity' => '2']);
        $prodRequirement = app(CreateProcurementAction::class)->execute($owner, $prodOrder, $prodLine, $supplier, ['quantity' => '2']);
        $this->assertSame('APV-000001', $prodRequirement->procurement_number);
        $prodPo = app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->forSalesOrder($owner, $prodOrder->fresh(), $supplier);
        $this->assertSame('BCF-000001', $prodPo->purchase_order_number);
        app(ReceiveProcurementAction::class)->execute($owner, $prodRequirement->fresh(), $warehouse, ['quantity' => '2', 'purchase_order_line_id' => $prodPo->lines->first()->id]);
        $this->assertSame('2.0000', $this->balance($org, $warehouse, $variant)->on_hand);
        $this->assertSame(1, DB::table('procurement_receipts')->count());
        $this->assertSame(1, DB::table('purchase_orders')->count());

        // Test stock is untouched by the Production receipt.
        $this->enterTest();
        $this->assertSame('3.0000', InventoryBalance::query()->where('warehouse_id', $warehouse->id)->value('on_hand'));
        $this->assertSame(1, PurchaseOrder::query()->count());
        $this->assertSame('TEST-PO-000001', PurchaseOrder::query()->value('purchase_order_number'));
    }

    public function test_price_hints_read_the_active_environment_purchase_history_only(): void
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $supplier = $this->supplier($org);
        $this->actingAs($owner);
        $catalog = app(SupplierCatalogQuery::class);
        $key = $supplier->id.':'.$variant->id;

        // Production purchase history (ordered PO at 10.00).
        $prodOrder = $this->createDraftOrder($owner, $org, $store);
        $prodLine = $this->addCatalogLine($owner, $prodOrder, $variant, $warehouse, ['quantity' => '2']);
        app(CreateProcurementAction::class)->execute($owner, $prodOrder, $prodLine, $supplier, ['quantity' => '2']);
        $prodPo = app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->forSalesOrder($owner, $prodOrder->fresh(), $supplier);
        DB::table('purchase_order_lines')->where('id', $prodPo->lines->first()->id)->update(['unit_price' => '10.0000']);
        DB::table('purchase_orders')->where('id', $prodPo->id)->update(['status' => 'ordered']);
        $this->assertSame('BCF-000001', $catalog->lastPurchases($org->id, [$variant->id], null)[$key]['purchase_order_number']);

        // Test Mode: Production history is invisible; the Test order page renders.
        $this->enterTest();
        $this->assertSame([], $catalog->lastPurchases($org->id, [$variant->id], null));
        $quote = $this->createQuotation($owner, $org, $store);
        $line = $this->addCatalogQuotationLine($owner, $quote, $variant, ['quantity' => '3']);
        $this->post("/quotations/{$quote->id}/lines/{$line->id}/supplier-requests", ['supplier_id' => $supplier->id])->assertRedirect();
        $request = QuotationSupplierAvailabilityRequest::query()->sole();
        $this->patch("/quotations/{$quote->id}/supplier-requests/{$request->id}", ['outcome' => 'available', 'confirmed_quantity' => '3'])->assertRedirect();
        $this->post("/quotations/{$quote->id}/issue")->assertRedirect();
        $this->post("/quotations/{$quote->id}/accept")->assertRedirect();
        $this->post("/quotations/{$quote->id}/conversion", ['warehouse_id' => $warehouse->id])->assertRedirect();
        $order = SalesOrder::query()->findOrFail(Quotation::findOrFail($quote->id)->converted_sales_order_id);
        $this->get("/sales/orders/{$order->id}")->assertOk();
        $this->post("/sales/orders/{$order->id}/purchase-orders", ['supplier_id' => $supplier->id])->assertRedirect()->assertSessionHasNoErrors();
        $testPo = PurchaseOrder::query()->sole();
        PurchaseOrderLine::query()->sole()->forceFill(['unit_price' => '77.0000'])->save();
        $testPo->forceFill(['status' => 'ordered'])->save();

        $history = $catalog->lastPurchases($org->id, [$variant->id], null);
        $this->assertSame('TEST-PO-000001', $history[$key]['purchase_order_number']);
        $this->assertSame('77.0000', $history[$key]['unit_price']);
        $this->assertSame('1', (string) $history[$key]['order_count']);
        $this->get("/sales/orders/{$order->id}")->assertOk();

        // Back in Production: only the Production history, untouched.
        $this->leaveTest();
        $back = $catalog->lastPurchases($org->id, [$variant->id], null);
        $this->assertSame('BCF-000001', $back[$key]['purchase_order_number']);
        $this->assertSame('10.0000', $back[$key]['unit_price']);
        $this->get("/sales/orders/{$prodOrder->id}")->assertOk();
    }

    public function test_cross_environment_links_are_rejected_and_rollback_is_guarded(): void
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $supplier = $this->supplier($org);
        $this->actingAs($owner);
        $prodOrder = $this->createDraftOrder($owner, $org, $store);
        $prodLine = $this->addCatalogLine($owner, $prodOrder, $variant, $warehouse, ['quantity' => '2']);
        $prodRequirement = app(CreateProcurementAction::class)->execute($owner, $prodOrder, $prodLine, $supplier, ['quantity' => '2']);

        $this->enterTest();
        $testOrder = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $testOrder, $variant, $warehouse, ['quantity' => '2']);

        // Test record -> Production order: refused by the model guard.
        $this->assertHttpStatus(404, fn () => $this->newRequirement($org, $store, $supplier, $variant, $prodOrder->id)->save());
        $this->assertHttpStatus(404, function () use ($org, $prodOrder, $prodLine, $variant) {
            $line = new PurchaseOrderLine;
            foreach (['organization_id' => $org->id, 'purchase_order_id' => 1, 'sales_order_procurement_id' => 1, 'sales_order_id' => $prodOrder->id,
                'sales_order_line_id' => $prodLine->id, 'product_variant_id' => $variant->id, 'position' => 1, 'quantity' => '1'] as $key => $value) {
                $line->{$key} = $value;
            }
            $line->save();
        });
        // A Test requirement can never be tied to a Production transfer request.
        $this->assertHttpStatus(403, function () use ($org, $store, $supplier, $variant, $testOrder) {
            $requirement = $this->newRequirement($org, $store, $supplier, $variant, $testOrder->id);
            $requirement->transfer_request_id = 1;
            $requirement->save();
        });
        // A Production procurement record cannot be acted upon from Test Mode (direct action call).
        $this->assertHttpStatus(404, fn () => app(ReceiveProcurementAction::class)->execute($owner, $prodRequirement, $warehouse, ['quantity' => '1']));
        $this->assertHttpStatus(404, fn () => app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->forSalesOrder($owner, $prodOrder, $supplier));
        // Test receipt -> a movement that is not on the Test ledger: refused by the composite FK.
        $requirement = $this->newRequirement($org, $store, $supplier, $variant, $testOrder->id);
        $requirement->procurement_number = 'TEST-PR-X';
        $requirement->save();
        try {
            ProcurementReceipt::query()->getModel()->newQuery()->insert(['sandbox_id' => $requirement->sandbox_id, 'organization_id' => $org->id,
                'sales_order_procurement_id' => $requirement->id, 'warehouse_id' => $warehouse->id, 'quantity' => '1', 'received_on' => now()->toDateString(),
                'inventory_movement_id' => 999999, 'inventory_reservation_id' => 999999, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('Test receipt referenced a non-Test movement.');
        } catch (QueryException) {
            $this->assertSame(0, ProcurementReceipt::query()->count());
        }

        // Production side: a Production requirement cannot point at a Test order.
        $this->leaveTest();
        $this->assertHttpStatus(404, fn () => $this->newRequirement($org, $store, $supplier, $variant, $testOrder->id)->save());

        // Rollback guard.
        $this->enterTest();
        $migration = require database_path('migrations/2026_11_21_000006_create_sandbox_procurement.php');
        try {
            $migration->down();
            $this->fail('Rollback dropped the Test procurement ledger with Test records present.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Test procurement records exist', $e->getMessage());
        }
        foreach (['sandbox_sales_order_procurements', 'sandbox_purchase_orders', 'sandbox_purchase_order_lines', 'sandbox_procurement_receipts', 'sandbox_quotation_supplier_availability_requests'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    private function enterTest(): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }

    private function leaveTest(): void
    {
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }

    private function supplier($org): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $org->id;
        $supplier->name = 'Fournisseur partagé';
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }

    private function newRequirement($org, $store, Supplier $supplier, $variant, int $salesOrderId): SalesOrderProcurement
    {
        $requirement = new SalesOrderProcurement;
        foreach (['organization_id' => $org->id, 'store_id' => $store->id, 'procurement_number' => 'X-'.$salesOrderId, 'supplier_id' => $supplier->id,
            'sales_order_id' => $salesOrderId, 'product_variant_id' => $variant->id, 'quantity' => '1'] as $key => $value) {
            $requirement->{$key} = $value;
        }

        return $requirement;
    }

    /** @return array<string, mixed> Production procurement ledger + sequences (read through the sanctioned bypass). */
    private function productionProcurement(): array
    {
        return SandboxInventoryGuard::bypass(fn () => collect(['sales_order_procurements', 'purchase_orders', 'purchase_order_lines', 'procurement_receipts',
            'quotation_supplier_availability_requests', 'procurement_sequences', 'purchase_order_sequences'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])
            ->all());
    }

    /** @return array<string, mixed> Production stock ledger. */
    private function productionStock(): array
    {
        return SandboxInventoryGuard::bypass(fn () => [
            'balances' => DB::table('inventory_balances')->orderBy('id')->get(['id', 'on_hand', 'reserved'])->map(fn ($r) => (array) $r)->all(),
            'movements' => DB::table('inventory_movements')->count(),
            'reservations' => DB::table('inventory_reservations')->count(),
        ]);
    }

    private function assertHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
