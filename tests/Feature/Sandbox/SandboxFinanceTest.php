<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Http\Controllers\PosController;
use App\Models\CommissionEntry;
use App\Models\CommissionRuleSet;
use App\Models\FinanceExpectedCollection;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commissions\CommissionRuleManager;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\FinancePeriod;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxInventoryGuard;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

class SandboxFinanceTest extends QuotationTestCase
{
    public function test_test_pos_checkout_uses_test_stock_and_test_payment_and_production_checkout_is_unchanged(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $production = $this->productionFinance($org);
        $positions = $this->accountPositions();

        $this->enterTest();
        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->postJson("/pos/drafts/{$cart}/lines", $this->catalogLine($variant, $warehouse, '2'))->assertOk();
        $total = (string) SalesOrder::query()->findOrFail($cart)->total_incl_tax;
        $this->post('/pos/sales', ['client_operation_id' => (string) Str::uuid(), 'order_id' => $cart,
            'payments' => [['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => $total, 'cash_received' => $total, 'reference' => null]]])
            ->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();

        $order = SalesOrder::query()->findOrFail($cart);
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame('fulfilled', $order->fulfillment_status->value); // Phase 7: Test checkout hands over on the spot, like Production
        $payment = Payment::query()->sole();
        $this->assertSame('sandbox_payments', $payment->getTable());
        $this->assertStringStartsWith('TEST-PAY-', $payment->payment_number);
        $this->assertSame($order->id, PaymentAllocation::query()->sole()->sales_order_id);
        // The hand-over consumed the Test reservation (Test ledger only).
        $this->assertSame(0, DB::table('sandbox_inventory_reservations')->where('status', 'active')->count());
        $this->assertSame('3.0000', Decimal::normalize((string) DB::table('sandbox_inventory_balances')->value('on_hand')));
        $this->assertSame('0.0000', app(SalesOrderPaymentCalculator::class)->remainingAmount($order));
        $this->get("/payments/{$payment->id}")->assertOk();
        $this->get('/payments')->assertOk();

        $this->leaveTest();
        $this->assertEquals($production, $this->productionFinance($org));
        $this->assertEquals($positions, $this->accountPositions());
        $this->assertSame('5.0000', $this->balance($org, $warehouse, $variant)->on_hand);

        // Production checkout keeps its exact behaviour (official number, fulfilled on the spot).
        $prodCart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->postJson("/pos/drafts/{$prodCart}/lines", $this->catalogLine($variant, $warehouse, '1'))->assertOk();
        $prodTotal = (string) SalesOrder::query()->findOrFail($prodCart)->total_incl_tax;
        $this->post('/pos/sales', ['client_operation_id' => (string) Str::uuid(), 'order_id' => $prodCart,
            'payments' => [['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => $prodTotal, 'cash_received' => $prodTotal, 'reference' => null]]])
            ->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();
        $prodOrder = SalesOrder::query()->findOrFail($prodCart);
        $this->assertSame('fulfilled', $prodOrder->fulfillment_status->value);
        $this->assertSame('PAY-000001', Payment::query()->sole()->payment_number);
        $this->assertSame('4.0000', $this->balance($org, $warehouse, $variant)->on_hand);
        $this->assertNotEquals($positions, $this->accountPositions());
    }

    public function test_test_payments_refunds_commissions_and_collections_stay_on_the_test_ledger(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash, $bank, $tpe] = $this->fixture();
        $rule = $this->activateRule($owner, $org);
        $production = $this->productionFinance($org);
        $positions = $this->accountPositions();

        $this->enterTest();
        $order = $this->confirmedTestOrder($owner, $org, $store, $variant, $warehouse);
        $this->assertSame(1, CommissionEntry::query()->count());
        $this->assertSame('sandbox_commission_entries', CommissionEntry::query()->sole()->getTable());
        $this->assertSame($rule->id, CommissionEntry::query()->sole()->commission_rule_set_id);
        $half = Decimal::normalize(bcdiv((string) $order->total_incl_tax, '2', 4));
        $this->post("/sales/orders/{$order->id}/payments", ['client_operation_id' => (string) Str::uuid(), 'payments' => [
            ['method' => 'bank_transfer', 'financial_account_id' => $bank->id, 'amount' => $half, 'payment_date' => now()->toDateString()],
            ['method' => 'card', 'financial_account_id' => $tpe->id, 'amount' => $half, 'payment_date' => now()->toDateString()],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame('paid', $order->fresh()->payment_status->value);

        $this->post('/finance/situation-fin-de-mois/expected-collections', ['sales_order_id' => $order->id, 'expected_date' => now()->toDateString(), 'expected_amount' => '1'])
            ->assertSessionHasErrors(); // fully paid: nothing left to expect — evaluated on Test payments only
        $open = $this->confirmedTestOrder($owner, $org, $store, $variant, $warehouse);
        $this->post('/finance/situation-fin-de-mois/expected-collections', ['sales_order_id' => $open->id, 'expected_date' => now()->toDateString(), 'expected_amount' => '10'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('sandbox_finance_expected_collections', FinanceExpectedCollection::query()->sole()->getTable());

        // Test refund: cancelling the paid Test order refunds on the Test ledger.
        $this->post("/sales/orders/{$order->id}/cancel", ['reason' => 'Annulation test'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame(2, PaymentRefund::query()->count());
        $this->assertSame('sandbox_payment_refunds', PaymentRefund::query()->first()->getTable());
        $this->assertTrue(PaymentRefund::query()->get()->every(fn ($refund) => str_starts_with($refund->refund_number, 'TEST-REF-')));
        $this->assertSame(2, CommissionEntry::query()->where('sales_order_id', $order->id)->count()); // original + reversal

        // Raw SQL on Production finance tables is refused in Test Mode.
        try {
            DB::table('payments')->sum('amount');
            $this->fail('Production payments readable in Test Mode.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('payments', $e->getMessage());
        }

        $this->leaveTest();
        $this->assertEquals($production, $this->productionFinance($org));
        $this->assertEquals($positions, $this->accountPositions());
        $this->assertSame(0, CommissionEntry::query()->count());
        $this->assertNull(Payment::query()->first());
        // Production reports (raw report queries) never show Test transactions.
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));
        $events = app(FinanceJournalService::class)->events($org, $period, null);
        $this->assertFalse(collect($events)->contains(fn ($event) => str_contains(json_encode($event), 'TEST-')));
        foreach (['/finance/journal', '/finance/ca-encaisse', '/finance/creances', '/finance/commissions/ledger', '/finance/commissions', '/payments'] as $page) {
            $this->get($page)->assertOk()->assertDontSee('TEST-PAY-')->assertDontSee('TEST-REF-')->assertDontSee($order->order_number)->assertDontSee($open->order_number);
        }
    }

    public function test_cross_environment_payment_links_are_rejected_and_store_scope_holds(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $productionOrder = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $productionOrder, $variant, $warehouse);
        $productionOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $productionOrder)->fresh();
        $productionPayment = $this->recordPayment($owner, $productionOrder, $cash, '1.0000');

        $this->enterTest();
        $testOrder = $this->confirmedTestOrder($owner, $org, $store, $variant, $warehouse);
        // Test session -> Production order: unreachable.
        $this->post("/sales/orders/{$productionOrder->id}/payments", $this->paymentPayload($cash))->assertNotFound();
        $this->assertHttpStatus(404, fn () => $this->recordPayment($owner, SalesOrder::query()->withoutGlobalScopes()->findOrFail($productionOrder->id), $cash, '1.0000'));
        $this->get("/payments/{$productionPayment->id}")->assertNotFound();
        // A Test allocation can never point at a Production order.
        $this->assertHttpStatus(404, function () use ($org, $productionOrder) {
            $allocation = new PaymentAllocation;
            $allocation->organization_id = $org->id;
            $allocation->payment_id = 1;
            $allocation->sales_order_id = $productionOrder->id;
            $allocation->amount = '1.0000';
            $allocation->save();
        });
        $this->post("/sales/orders/{$testOrder->id}/payments", $this->paymentPayload($cash))->assertRedirect()->assertSessionHasNoErrors();
        $testPayment = Payment::query()->sole();

        // Store scope: another store of the same organization cannot see the Test payment.
        $otherStore = $this->createStore($org, $owner, 'Other store');
        $this->activate($owner, $org, $otherStore);
        $this->get("/payments/{$testPayment->id}")->assertNotFound();
        $this->activate($owner, $org, $store);
        // Test payment reversal stays on the Test ledger.
        $this->post("/payments/{$testPayment->id}/reverse", ['reason' => 'Erreur de saisie test'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reversed', $testPayment->fresh()->status->value);
        $this->assertSame('posted', SandboxInventoryGuard::bypass(fn () => DB::table('payments')->where('id', $productionPayment->id)->value('status')));

        $this->leaveTest();
        // Production session -> Test order / Test payment: unreachable.
        $this->post("/sales/orders/{$testOrder->id}/payments", $this->paymentPayload($cash))->assertNotFound();
        $this->assertHttpStatus(404, fn () => $this->recordPayment($owner, SalesOrder::query()->withoutGlobalScopes()->findOrFail($testOrder->id), $cash, '1.0000'));
        // Ids are per-ledger: whatever Production row may share the id, the Test payment never surfaces.
        $this->get("/payments/{$testPayment->id}")->assertDontSee($testPayment->payment_number);
        $this->get('/payments')->assertOk()->assertDontSee($testPayment->payment_number)->assertSee($productionPayment->payment_number);
    }

    public function test_tenant_isolation_and_rollback_guard(): void
    {
        [$owner, $org, $store, $variant, $warehouse, $cash] = $this->fixture();
        $this->enterTest();
        $order = $this->confirmedTestOrder($owner, $org, $store, $variant, $warehouse);
        $this->post("/sales/orders/{$order->id}/payments", $this->paymentPayload($cash))->assertRedirect()->assertSessionHasNoErrors();

        $this->leaveTest(); // the Test selection belongs to this user's session
        $other = User::factory()->create();
        $otherOrg = $this->createOrganization($other);
        $otherStore = $this->createStore($otherOrg, $other);
        $this->activate($other, $otherOrg, $otherStore);
        $this->actingAs($other);
        $this->enterTest();
        $this->assertSame(0, Payment::query()->count());
        $this->post("/sales/orders/{$order->id}/payments", $this->paymentPayload($cash))->assertNotFound();
        $this->actingAs($owner);

        $migration = require database_path('migrations/2026_11_21_000005_create_sandbox_finance.php');
        try {
            $migration->down();
            $this->fail('Rollback dropped the Test finance ledger with Test payments present.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Test finance records exist', $e->getMessage());
        }
        foreach (['sandbox_payments', 'sandbox_payment_allocations', 'sandbox_payment_refunds', 'sandbox_commission_entries',
            'sandbox_finance_expected_collections', 'sandbox_finance_expected_collection_matches'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    /** @return array{User, Organization, Store, ProductVariant, Warehouse, FinancialAccount, FinancialAccount, FinancialAccount} */
    private function fixture(): array
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $variant->purchase_price = '100.0000';
        $variant->save();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $variant, '5.0000');
        $cash = $this->createFinancialAccount($org, ['name' => 'Caisse', 'code' => 'CASH', 'type' => 'cash']);
        $bank = $this->createFinancialAccount($org, ['name' => 'Banque', 'code' => 'BANK', 'type' => 'bank']);
        $tpe = $this->createFinancialAccount($org, ['name' => 'TPE', 'code' => 'TPE', 'type' => 'card_clearing']);
        // Configured opening positions so the accounts page computes real cash / bank / TPE balances.
        foreach ([$cash, $bank, $tpe] as $account) {
            app(SetFinancialAccountOpeningPositionAction::class)->execute($owner, $account,
                ['effective_date' => now()->subDay()->toDateString(), 'amount' => '1000.0000', 'currency_code' => 'MAD']);
        }
        $this->actingAs($owner);

        return [$owner, $org, $store, $variant, $warehouse, $cash, $bank, $tpe];
    }

    private function confirmedTestOrder(User $owner, Organization $org, Store $store, ProductVariant $variant, Warehouse $warehouse): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $this->post("/sales/orders/{$order->id}/confirm")->assertRedirect()->assertSessionHasNoErrors();
        $order = SalesOrder::query()->findOrFail($order->id);
        $this->assertNotNull($order->sandbox_id);
        $this->assertSame('confirmed', $order->status->value);

        return $order;
    }

    private function activateRule(User $owner, Organization $org): CommissionRuleSet
    {
        $set = app(CommissionRuleManager::class)->create($owner, $org, ['name' => 'Commission', 'effective_from' => '2020-01-01'], [
            ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
            ['min_margin_rate' => '10', 'max_margin_rate' => null, 'commission_rate' => '10'],
        ]);

        return app(CommissionRuleManager::class)->activate($owner, $set);
    }

    /** @return array<string, mixed> */
    private function paymentPayload(FinancialAccount $account): array
    {
        return ['client_operation_id' => (string) Str::uuid(), 'payments' => [
            ['method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '1.0000', 'payment_date' => now()->toDateString()],
        ]];
    }

    /** @return array<string, mixed> */
    private function catalogLine(ProductVariant $variant, Warehouse $warehouse, string $quantity): array
    {
        return ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
            'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0'];
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

    /** @return array<string, mixed> Production cash/bank/TPE positions as the accounts page computes them. */
    private function accountPositions(): array
    {
        return $this->get('/financial-accounts')->assertOk()->viewData('page')['props']['positions'];
    }

    /** @return array<string, mixed> Full Production finance ledger (read through the sanctioned bypass). */
    private function productionFinance(Organization $org): array
    {
        return SandboxInventoryGuard::bypass(fn () => collect(['payments', 'payment_allocations', 'payment_refunds', 'commission_entries',
            'finance_expected_collections', 'finance_expected_collection_matches', 'payment_sequences', 'payment_refund_sequences'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy(Schema::hasColumn($table, 'id') ? 'id' : 'organization_id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all());
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
