<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Http\Controllers\PosController;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Services\Sandbox\SandboxInventoryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

class SandboxSalesTest extends QuotationTestCase
{
    public function test_same_terminal_environment_isolation_conversion_and_stock_guards(): void
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $terminal = (string) Str::uuid();
        $this->actingAs($owner)->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $terminal);
        $production = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $productionQuote = $this->createQuotation($owner, $org, $store);
        $sequence = DB::table('sales_order_sequences')->where('organization_id', $org->id)->value('next_number');
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $test = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->assertNotSame($production, $test);
        $this->assertSame($terminal, SalesOrder::findOrFail($test)->pos_terminal_id);
        $this->assertNull(SalesOrder::find($production));
        $this->assertNull(Quotation::find($productionQuote->id));
        $this->get('/sales/orders/'.$production)->assertNotFound();
        $this->post('/quotations/'.$productionQuote->id.'/conversion')->assertNotFound();
        $quote = $this->createQuotation($owner, $org, $store);
        $this->addCatalogQuotationLine($owner, $quote, $variant);
        $this->assertStringStartsWith('TEST-', $quote->quotation_number);
        $result = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $quote, ['warehouse_id' => $warehouse->id]);
        $order = $result['order'];
        $this->assertSame('draft', $order->status->value);
        $this->assertSame($quote->sandbox_id, $order->sandbox_id);
        $this->assertStringStartsWith('TEST-', $order->order_number);
        $this->assertSame($sequence, DB::table('sales_order_sequences')->where('organization_id', $org->id)->value('next_number'));
        $this->assertSame(0, SandboxInventoryGuard::bypass(fn () => DB::table('sales_order_inventory_allocations')->count()));
        $this->assertSame(0, SandboxInventoryGuard::bypass(fn () => DB::table('inventory_reservations')->count()));
        $this->assertSame(0, SandboxInventoryGuard::bypass(fn () => DB::table('inventory_movements')->count()));
        $this->assertSame(0, $order->procurements()->count());
        $otherStore = $this->createStore($org, $owner, 'Other store');
        $this->activate($owner, $org, $otherStore);
        $this->get('/sales/orders/'.$order->id)->assertNotFound();
        $this->get('/quotations/'.$quote->id)->assertNotFound();
        $this->activate($owner, $org, $store);
        // Phase 4: confirmation is enabled in Test Mode but evaluated on TEST
        // stock only — this fixture has none, so it fails and stays a draft.
        $this->post('/sales/orders/'.$order->id.'/confirm')->assertSessionHasErrors();
        $this->post('/quotations/'.$quote->id.'/issue')->assertForbidden();
        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order);
            $this->fail('Confirmation allowed without Test stock');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
        foreach (['/sales/orders/'.$order->id.'/cancel', '/sales/orders/'.$order->id.'/payments', '/sales/orders/'.$order->id.'/purchase-orders',
            '/sales/orders/'.$order->id.'/fulfill', '/sales/orders/'.$order->id.'/returns', '/sales/orders/'.$order->id.'/procurements',
            '/quotations/'.$quote->id.'/accept', '/quotations/'.$quote->id.'/email', '/quotations/'.$quote->id.'/stamp',
            '/pos/drafts/'.$test.'/convert', '/pos/sales'] as $blocked) {
            $this->postJson($blocked, [])->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        }
        $this->assertSame('draft', DB::table('sales_orders')->where('id', $order->id)->value('status'));
        $this->assertSame(0, DB::table('payments')->count() + DB::table('invoices')->count());
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $this->assertNull(Quotation::find($quote->id));
        $this->assertNull(SalesOrder::find($order->id));
        $this->assertNotNull(SalesOrder::find($production));
        try {
            app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $quote);
            $this->fail('Cross-environment conversion allowed');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->post('/quotations/'.$quote->id.'/conversion')->assertNotFound();
        $this->assertSame($production, $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id'));
    }

    public function test_rollback_refuses_to_drop_isolation_while_test_records_exist(): void
    {
        [$owner, $org, $store] = $this->quotationCatalogFixture();
        $this->actingAs($owner);
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk(); // resolves the Test context for direct action calls
        $quote = $this->createQuotation($owner, $org, $store);
        $this->assertNotNull($quote->sandbox_id);
        $migration = require database_path('migrations/2026_11_21_000002_isolate_sandbox_sales.php');
        try {
            $migration->down();
            $this->fail('Rollback dropped isolation with Test records present');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Test sales records exist', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('quotations', 'sandbox_id'));
        $this->assertTrue(Schema::hasColumn('sales_orders', 'sandbox_id'));
        $this->assertSame(1, DB::table('quotations')->whereNotNull('sandbox_id')->count());
    }
}
