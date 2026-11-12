<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Http\Controllers\PosController;
use App\Models\CommissionEntry;
use App\Models\CreditNote;
use App\Models\CustomerExchange;
use App\Models\CustomerReturn;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\Commissions\CommissionRuleManager;
use App\Services\Sandbox\SandboxInventoryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

/** Phase 8: Test returns, exchanges, refunds, credit notes and commission reversals on the Test ledgers. */
class SandboxReturnsTest extends QuotationTestCase
{
    public function test_test_after_sales_flow_stays_on_the_test_ledgers_and_production_is_unchanged(): void
    {
        [$owner, $org, $store, $a, $b, $warehouse, $cash] = $this->fixture();
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());

        // A Production sale with a draft Production return exists before Test Mode.
        $prodOrder = $this->posSale($warehouse, $cash, $a, '2');
        $this->issueProductionInvoice($owner, $prodOrder);
        $prodReturn = app(CreateCustomerReturnAction::class)->execute($owner, $prodOrder->fresh(['lines']), [['sales_order_line_id' => $prodOrder->lines()->value('id'), 'quantity' => '1']], 'Retour production', 'restock', (string) Str::uuid());
        $this->assertSame(1, DB::table('customer_returns')->count());
        $production = $this->productionState();

        $this->enterTest();
        $order = $this->posSale($warehouse, $cash, $a, '3');
        $this->assertNotNull($order->sandbox_id);
        $this->assertSame(1, CommissionEntry::query()->count());
        $testStock = fn () => InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $a->id)->value('on_hand');
        $this->assertSame('5.0000', $testStock()); // Production had 8 left when the Test snapshot was taken, minus 3 sold here
        $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        $this->post("/invoices/{$invoice->id}/issue")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringStartsWith('TEST-FAC-', $invoice->fresh()->invoice_number);
        $line = $order->lines()->sole();

        // Cross-environment: a Test order/return cannot touch Production ones, in both directions.
        $this->postJson("/sales/orders/{$prodOrder->id}/returns", ['client_operation_id' => (string) Str::uuid(), 'reason' => 'x', 'disposition' => 'restock',
            'lines' => [['sales_order_line_id' => $prodOrder->lines()->value('id'), 'quantity' => '1']]])->assertNotFound();
        $this->post("/sales/returns/{$prodReturn->id}/receive")->assertNotFound();
        $this->get("/sales/returns/{$prodReturn->id}")->assertNotFound();
        $this->assertHttpStatus(404, fn () => (new CustomerReturn)->forceFill(['organization_id' => $org->id, 'store_id' => $store->id, 'sales_order_id' => $prodOrder->id,
            'warehouse_id' => $warehouse->id, 'return_number' => 'X', 'client_operation_id' => (string) Str::uuid(), 'status' => 'draft', 'disposition' => 'restock',
            'reason' => 'x', 'currency_code' => 'MAD', 'policy_snapshot' => []])->save());
        $this->assertSame(0, CustomerReturn::query()->count());

        // Return 1 of 3: Test numbering, Test credit-note draft, no stock effect until received.
        $this->get("/sales/orders/{$order->id}/returns/create")->assertOk();
        $this->post("/sales/orders/{$order->id}/returns", ['client_operation_id' => (string) Str::uuid(), 'reason' => 'Retour test', 'disposition' => 'restock',
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '1']]])->assertRedirect()->assertSessionHasNoErrors();
        $return = CustomerReturn::query()->sole();
        $this->assertNotNull($return->sandbox_id);
        $this->assertSame('TEST-RET-000001', $return->return_number);
        $this->assertSame('5.0000', $testStock());
        $note = CreditNote::query()->sole();
        $this->assertSame(['draft', $return->sandbox_id], [$note->status, $note->sandbox_id]);
        $this->get('/sales/returns')->assertOk()->assertSee('TEST-RET-000001');
        $this->get("/sales/returns/{$return->id}")->assertOk();

        // Receive: Test stock +1, credit note issued with a TEST-AV number, Test commission reversed.
        $this->post("/sales/returns/{$return->id}/receive")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('6.0000', $testStock());
        $this->assertSame(1, DB::table('sandbox_inventory_movements')->where('movement_type', 'customer_return')->count());
        $note = $note->fresh();
        $this->assertSame(['issued', 'TEST-AV-000001'], [$note->status, $note->credit_note_number]);
        $this->assertSame(1, CommissionEntry::query()->where('entry_type', CommissionEntry::TYPE_RETURN_REVERSAL)->count());
        $this->assertSame('sandbox_commission_entries', CommissionEntry::query()->first()->getTable());

        // Credit-note document: view / print / PDF with the Test marking.
        $this->get("/credit-notes/{$note->id}")->assertOk();
        $this->get("/credit-notes/{$note->id}/print")->assertOk()->assertSee('DOCUMENT DE TEST')->assertSee('TEST-AV-000001');
        $this->get("/credit-notes/{$note->id}/pdf")->assertOk();

        // Test refund: Test payment only, TEST-REF number.
        $payment = Payment::query()->sole();
        $this->post("/sales/returns/{$return->id}/refunds", ['payment_id' => $payment->id, 'amount' => (string) $return->total_incl_tax,
            'reason' => 'Remboursement test', 'client_operation_id' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $refund = PaymentRefund::query()->sole();
        $this->assertSame('sandbox_payment_refunds', $refund->getTable());
        $this->assertStringStartsWith('TEST-REF-', $refund->refund_number);
        $this->assertSame($return->id, $refund->customer_return_id);

        // Exchange 1 unit of A for the cheaper B: return restocked, replacement consumed from Test stock, difference refunded.
        $this->get("/sales/orders/{$order->id}/exchanges/create")->assertOk();
        $this->post("/sales/orders/{$order->id}/exchanges", ['client_operation_id' => (string) Str::uuid(), 'reason' => 'Échange test', 'disposition' => 'restock',
            'returned_items' => [['sales_order_line_id' => $line->id, 'quantity' => '1']], 'replacement_items' => [['product_variant_id' => $b->id, 'quantity' => '1']]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $exchange = CustomerExchange::query()->sole();
        $this->assertSame('TEST-ECH-000001', $exchange->exchange_number);
        $this->assertNotNull($exchange->sandbox_id);
        $this->assertSame('TEST-RET-000002', $exchange->customerReturn->return_number);
        $this->post("/sales/exchanges/{$exchange->id}/receive")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('7.0000', $testStock());
        $bStock = fn () => InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $b->id)->value('on_hand');
        $this->assertSame('10.0000', $bStock());
        $this->post("/sales/exchanges/{$exchange->id}/fulfill")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('9.0000', $bStock());
        $this->post("/sales/exchanges/{$exchange->id}/fulfill")->assertSessionHasErrors(); // never consumed twice
        $this->assertSame('9.0000', $bStock());
        $exchange = $exchange->fresh();
        $this->assertSame('awaiting_settlement', $exchange->status);
        $this->post("/sales/exchanges/{$exchange->id}/refunds", ['client_operation_id' => (string) Str::uuid(), 'payment_id' => $payment->id,
            'amount' => ltrim((string) $exchange->difference_amount, '-'), 'reason' => 'Différence échange'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $exchange->fresh()->status);
        $this->assertSame(2, PaymentRefund::query()->count());
        $this->get("/sales/exchanges/{$exchange->id}")->assertOk();
        $this->get("/sales/orders/{$order->id}")->assertOk();
        $this->assertSame(2, CreditNote::query()->count());
        $this->assertSame('TEST-AV-000002', CreditNote::query()->orderByDesc('id')->value('credit_note_number'));

        // Production is unchanged and cannot see any Test after-sales record.
        $this->leaveTest();
        $this->assertEquals($production, $this->productionState());
        $this->assertSame(1, CustomerReturn::query()->count());
        $this->assertSame(0, CustomerExchange::query()->count());
        $this->assertSame(1, CreditNote::query()->count()); // only the Production draft
        $this->get("/sales/returns/{$return->id}")->assertNotFound();
        $this->get("/credit-notes/{$note->id}")->assertNotFound();
        $this->post("/sales/exchanges/{$exchange->id}/cancel", ['reason' => 'x'])->assertNotFound();
        $this->get('/sales/returns')->assertOk()->assertDontSee('TEST-RET-');
        $this->assertHttpStatus(404, fn () => app(ReceiveCustomerReturnAction::class)->execute($owner, $return));

        // The Production return flow is unchanged: official numbers, Production stock, Production sequences only.
        $this->post("/sales/returns/{$prodReturn->id}/receive")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringStartsWith('RET-', $prodReturn->fresh()->return_number);
        $prodNote = CreditNote::query()->sole();
        $this->assertMatchesRegularExpression('/^AV-\d{5}\/\d{4}$/', $prodNote->credit_note_number);
        $this->assertNull($prodNote->sandbox_id);
        $this->assertSame('9.0000', (string) InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $a->id)->value('on_hand'));
        $this->assertSame(1, DB::table('credit_note_sequences')->count());
        $this->enterTest();
        $this->assertSame('7.0000', $testStock()); // Test stock untouched by the Production receipt
    }

    public function test_test_exchange_with_extra_payment_uses_the_test_payment_ledger_and_pivot(): void
    {
        [$owner, $org, $store, $a, , $warehouse, $cash] = $this->fixture();
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $pricier = $this->createProduct($org, 'Pricier', 'PRICE-1', ['default_sale_price' => '800.0000'])->variants->firstOrFail();
        $this->openStock($owner, $org, $warehouse, $pricier, '5.0000');
        $production = $this->productionState();

        $this->enterTest();
        $order = $this->posSale($warehouse, $cash, $a, '1');
        $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/invoices/'.Invoice::query()->sole()->id.'/issue')->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/exchanges", ['client_operation_id' => (string) Str::uuid(), 'reason' => 'Échange test', 'disposition' => 'restock',
            'returned_items' => [['sales_order_line_id' => $order->lines()->sole()->id, 'quantity' => '1']], 'replacement_items' => [['product_variant_id' => $pricier->id, 'quantity' => '1']]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $exchange = CustomerExchange::query()->sole();
        $this->post("/sales/exchanges/{$exchange->id}/receive")->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/sales/exchanges/{$exchange->id}/fulfill")->assertRedirect()->assertSessionHasNoErrors();
        $exchange = $exchange->fresh();
        $this->assertSame('awaiting_settlement', $exchange->status);
        $this->assertTrue(bccomp((string) $exchange->difference_amount, '0', 4) > 0);

        $this->post("/sales/exchanges/{$exchange->id}/payments", ['client_operation_id' => (string) Str::uuid(), 'payments' => [
            ['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => (string) $exchange->difference_amount, 'payment_date' => now()->toDateString()],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $exchange->fresh()->status);
        $this->assertSame(1, DB::table('sandbox_customer_exchange_payments')->count());
        $this->assertSame(1, $exchange->fresh()->payments()->count());
        $this->get("/sales/exchanges/{$exchange->id}")->assertOk();

        $this->leaveTest();
        $this->assertEquals($production, $this->productionState());
        $this->assertSame(0, DB::table('customer_exchange_payments')->count());
    }

    private function fixture(): array
    {
        [$owner, $org, $store, $a] = $this->quotationCatalogFixture();
        $org->settings = [...($org->settings ?? []), 'return_policy' => ['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true,
            'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock']];
        $org->save();
        $a->purchase_price = '100.0000';
        $a->save();
        $b = $this->createProduct($org, 'Cheaper', 'CHEAP-1', ['default_sale_price' => '300.0000'])->variants->firstOrFail();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $a, '10.0000');
        $this->openStock($owner, $org, $warehouse, $b, '10.0000');
        $cash = $this->createFinancialAccount($org, ['name' => 'Caisse', 'code' => 'CASH', 'type' => 'cash']);
        app(SetFinancialAccountOpeningPositionAction::class)->execute($owner, $cash, ['effective_date' => now()->subDay()->toDateString(), 'amount' => '1000.0000', 'currency_code' => 'MAD']);
        $set = app(CommissionRuleManager::class)->create($owner, $org, ['name' => 'Commission', 'effective_from' => '2020-01-01'], [
            ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
            ['min_margin_rate' => '10', 'max_margin_rate' => null, 'commission_rate' => '10'],
        ]);
        app(CommissionRuleManager::class)->activate($owner, $set);
        $this->actingAs($owner);

        return [$owner, $org, $store, $a, $b, $warehouse, $cash];
    }

    private function posSale(Warehouse $warehouse, $cash, ProductVariant $variant, string $quantity): SalesOrder
    {
        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->postJson("/pos/drafts/{$cart}/lines", ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
            'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0'])->assertOk();
        $total = (string) SalesOrder::query()->findOrFail($cart)->total_incl_tax;
        $this->post('/pos/sales', ['client_operation_id' => (string) Str::uuid(), 'order_id' => $cart,
            'payments' => [['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => $total, 'cash_received' => $total, 'reference' => null]]])
            ->assertRedirect()->assertSessionHasNoErrors();

        return SalesOrder::query()->findOrFail($cart);
    }

    private function issueProductionInvoice($owner, SalesOrder $order): Invoice
    {
        return app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh()));
    }

    /** @return array<string, mixed> */
    private function productionState(): array
    {
        return SandboxInventoryGuard::bypass(fn () => collect(['customer_returns', 'customer_return_lines', 'customer_exchanges', 'credit_notes', 'credit_note_sequences',
            'customer_return_sequences', 'customer_exchange_sequences', 'customer_exchange_payments', 'payments', 'payment_refunds', 'commission_entries',
            'inventory_balances', 'inventory_movements'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)
                ->when(in_array($table, ['customer_returns', 'customer_return_lines', 'customer_exchanges', 'credit_notes'], true), fn ($query) => $query->whereNull('sandbox_id'))
                ->get()->map(fn ($row) => (array) $row)->all()])->all());
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
