<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Dashboards\ExecutiveDashboardService;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QuotationTestCase;

/**
 * Production reporting (Finance situation, executive dashboard) must never include
 * Test-mode records. Sales orders, invoices and credit notes share their tables with
 * Test rows (sandbox_id NOT NULL), so every raw Production reader has to exclude them;
 * payments/refunds are physically separate sandbox_* tables and need no filter.
 */
class SandboxReportingIsolationTest extends QuotationTestCase
{
    private const SALE_DATE = '2026-05-29'; // documentFixture() books its order on this date

    public function test_test_sales_invoices_and_credit_notes_never_reach_the_production_finance_situation(): void
    {
        [$owner, $org, $store, $production, $customer] = $this->documentFixture(total: '1000.0000');
        $this->actingAs($owner);
        $productionInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $production, ['invoice_date' => self::SALE_DATE]));
        $this->insertCreditNote($org->id, $store->id, $production, $productionInvoice, null, '100.0000');

        // Another organization's real activity in the same month (organization isolation).
        [, , , $foreignOrder] = $this->documentFixture(total: '7000.0000');
        $this->assertNotSame($org->id, $foreignOrder->organization_id);

        $period = FinancePeriod::fromMonth('2026-05');
        $report = app(FinanceMonthlyReportService::class);
        $before = $report->situation($org, $period, null);
        $realTotal = (string) $production->total_incl_tax;
        $this->assertSame(1, Decimal::compare($realTotal, '0.0000'));
        $this->assertSame(0, Decimal::compare($before['ventes'], $realTotal));
        $this->assertSame(0, Decimal::compare($before['facturation_brute'], (string) $productionInvoice->total_incl_tax));
        $this->assertSame(0, Decimal::compare($before['avoirs'], '100.0000'));
        $this->assertSame(0, Decimal::compare($before['ventes_nettes'], Decimal::subtract($realTotal, '100.0000')));

        // Test Mode: a confirmed Test sale, its issued Test invoice and an issued Test credit note, same month.
        $this->enterTest();
        $testOrder = $this->confirmedTestOrder($owner, $org, $store, $customer, '1190.0000');
        $testInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $testOrder, ['invoice_date' => self::SALE_DATE]));
        $this->assertNotNull($testInvoice->sandbox_id);
        $this->insertCreditNote($org->id, $store->id, $testOrder, $testInvoice, $testOrder->sandbox_id, '50.0000');
        $this->leaveTest();

        $after = $report->situation($org, $period, null);
        foreach (['ventes', 'facturation_brute', 'facturation', 'avoirs', 'ventes_nettes', 'encaissements', 'remboursements'] as $key) {
            $this->assertSame(0, Decimal::compare($after[$key], $before[$key]), "Production {$key} changed after a Test transaction");
        }
        $this->assertSame(0, Decimal::compare($report->ventesTotal($org, $period, $store), $realTotal));
        $this->assertSame(0, Decimal::compare($report->creditNotesTotal($org, $period, $store), '100.0000'));

        // The Test transactions still exist untouched — only the Production readers ignore them.
        $this->assertSame(1, DB::table('sales_orders')->where('organization_id', $org->id)->whereNotNull('sandbox_id')->count());
        $this->assertSame(1, DB::table('invoices')->where('organization_id', $org->id)->whereNotNull('sandbox_id')->where('status', 'issued')->count());
        $this->assertSame(1, DB::table('credit_notes')->where('organization_id', $org->id)->whereNotNull('sandbox_id')->count());
        // Without the environment filter the same raw sum WOULD include them (the regression this guards).
        $unfiltered = (string) DB::table('sales_orders')->where('organization_id', $org->id)->where('status', 'confirmed')->sum('total_incl_tax');
        $this->assertSame(1, Decimal::compare($unfiltered, $after['ventes']));
    }

    public function test_test_sale_never_appears_in_the_production_dashboard_while_real_sales_do(): void
    {
        [$owner, $org, $store, $first, $customer] = $this->documentFixture(total: '500.0000');
        $this->actingAs($owner);
        $period = FinancePeriod::fromMonth('2026-05');
        $dashboard = app(ExecutiveDashboardService::class);
        $before = $dashboard->main($org, $period, $store);

        $this->enterTest();
        $testOrder = $this->confirmedTestOrder($owner, $org, $store, $customer, '1190.0000');
        $testInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $testOrder, ['invoice_date' => self::SALE_DATE]));
        $this->insertCreditNote($org->id, $store->id, $testOrder, $testInvoice, $testOrder->sandbox_id, '50.0000');
        $this->leaveTest();

        $afterTest = $dashboard->main($org, $period, $store);
        $this->assertSame($before['primary']['net_sales'], $afterTest['primary']['net_sales']);
        $this->assertSame($before['primary']['orders'], $afterTest['primary']['orders']);
        $this->assertSame($before['charts']['sales_vs_collections'], $afterTest['charts']['sales_vs_collections']);
        $day = collect($afterTest['charts']['sales_vs_collections'])->firstWhere('date', self::SALE_DATE);
        $this->assertSame(0, Decimal::compare($day['sales'], (string) $first->total_incl_tax)); // only the real sale

        // A real Production sale on the same day still flows into KPI and chart.
        $real = $this->createDraftOrder($owner, $org, $store, $customer, ['sale_date' => self::SALE_DATE]);
        $this->addCustomLine($owner, $real, ['description' => 'Real sale', 'unit_price_excl_tax' => '250.0000']);
        $real = app(ConfirmSalesOrderAction::class)->execute($owner, $real)->fresh();
        $this->assertNull($real->sandbox_id);
        $expected = Decimal::add((string) $first->total_incl_tax, (string) $real->total_incl_tax);

        $afterReal = $dashboard->main($org, $period, $store);
        $realDay = collect($afterReal['charts']['sales_vs_collections'])->firstWhere('date', self::SALE_DATE);
        $this->assertSame(0, Decimal::compare($realDay['sales'], $expected));
        $this->assertSame(0, Decimal::compare($afterReal['primary']['net_sales']['value'], $expected));
    }

    public function test_test_mode_keeps_seeing_its_own_records_through_the_environment_scope(): void
    {
        [$owner, $org, $store, $production, $customer] = $this->documentFixture();
        $this->actingAs($owner);

        $this->enterTest();
        $testOrder = $this->confirmedTestOrder($owner, $org, $store, $customer, '80.0000');
        // In Test Mode the scoped (Eloquent) readers return the Test order and never the Production one.
        $this->assertSame([$testOrder->id], SalesOrder::query()->where('organization_id', $org->id)->pluck('id')->all());
        $this->get('/sales/orders')->assertOk()->assertSee($testOrder->order_number)->assertDontSee($production->order_number);
        $this->leaveTest();

        // Back in Production: the mirror image.
        $this->assertSame([$production->id], SalesOrder::query()->where('organization_id', $org->id)->pluck('id')->all());
        $this->get('/sales/orders')->assertOk()->assertSee($production->order_number)->assertDontSee($testOrder->order_number);
    }

    private function confirmedTestOrder(User $owner, $org, $store, $customer, string $unitPrice): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $org, $store, $customer, ['sale_date' => self::SALE_DATE]);
        $this->addCustomLine($owner, $order, ['description' => 'Test sale', 'unit_price_excl_tax' => $unitPrice]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $this->assertNotNull($order->sandbox_id);
        $this->assertStringStartsWith('TEST-', $order->order_number);
        $this->assertSame('confirmed', $order->status->value);

        return $order;
    }

    private function enterTest(): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk(); // resolves the Test context for direct action calls
    }

    private function leaveTest(): void
    {
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk(); // resolves the Production context again
    }

    /** Minimal raw issued credit note (+ its return), same convention as SandboxDocumentsTest. */
    private function insertCreditNote(int $organizationId, int $storeId, SalesOrder $order, Invoice $invoice, ?int $sandboxId, string $total): int
    {
        $warehouse = $this->createWarehouse($order->organization, 'CN-'.Str::random(4), 'CN'.Str::random(4));
        $returnId = DB::table('customer_returns')->insertGetId([
            'organization_id' => $organizationId, 'store_id' => $storeId, 'sales_order_id' => $order->id, 'warehouse_id' => $warehouse->id,
            'return_number' => ($sandboxId ? 'TEST-RET-' : 'R-').Str::random(8), 'client_operation_id' => (string) Str::uuid(), 'status' => 'received',
            'reason' => 'x', 'currency_code' => 'MAD', 'policy_snapshot' => '{}', 'sandbox_id' => $sandboxId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('credit_notes')->insertGetId([
            'organization_id' => $organizationId, 'store_id' => $storeId, 'customer_return_id' => $returnId, 'invoice_id' => $invoice->id,
            'sales_order_id' => $order->id, 'credit_note_number' => ($sandboxId ? 'TEST-AV-' : 'AV-').Str::random(6), 'status' => 'issued',
            'credit_note_date' => self::SALE_DATE, 'currency_code' => 'MAD', 'subtotal_excl_tax' => $total, 'discount_total' => 0, 'tax_total' => 0,
            'total_incl_tax' => $total, 'reason' => 'x', 'seller_snapshot' => '{}', 'customer_snapshot' => '{}', 'sandbox_id' => $sandboxId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
