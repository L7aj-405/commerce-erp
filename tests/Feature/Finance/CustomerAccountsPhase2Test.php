<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\ManageCustomerOpeningBalanceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\CustomerFinancialPositionService;
use App\Services\Finance\CustomerStatementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 2 / FC-02 + FC-03 + FC-04 over HTTP: accounts index, statement, settlement, invoicing sections. */
class CustomerAccountsPhase2Test extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private FinancialAccount $account;

    private Customer $a;

    private Customer $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->a = $this->createCustomer($this->org, 'Atlas');
        $this->b = $this->createCustomer($this->org, 'Bravo');
        $this->actingAs($this->owner);
    }

    private function order(Customer $customer, string $total): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    private function member(array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->org, $user, $permissions, 'active', 'R'.Str::random(5));
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->org, $this->store);

        return $user;
    }

    public function test_statement_reconciles_with_the_position_and_opening_balance_is_not_revenue(): void
    {
        $o1 = $this->order($this->a, '1000.0000');
        $this->order($this->a, '500.0000');
        $this->post(route('finance.clients.accounts.settlement.store', $this->a), [
            'client_operation_id' => (string) Str::uuid(), 'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => '400.0000',
            'payment_date' => now()->toDateString(), 'allocations' => [['sales_order_id' => $o1->id, 'amount' => '400.0000']],
        ])->assertSessionHasNoErrors();
        $this->post(route('finance.clients.accounts.opening.store', $this->a), [
            'effective_date' => now()->subYear()->toDateString(), 'amount' => '200.0000', 'currency_code' => 'MAD', 'reference' => 'REPRISE', 'reason' => 'Dette historique',
        ])->assertSessionHasNoErrors();

        $statement = app(CustomerStatementService::class)->build($this->org, $this->a, $this->owner, now()->subYears(2)->toDateString(), now()->toDateString());
        $position = app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$this->a->id], $this->owner)[$this->a->id];
        $this->assertSame('0.0000', $statement['opening']);
        $this->assertSame('1300.0000', $statement['closing']);
        $this->assertSame('1700.0000', $statement['total_debit']);
        $this->assertSame('400.0000', $statement['total_credit']);
        $this->assertSame($position['credit_exposure'], $statement['closing'], 'statement closing reconciles with receivable + unbilled + opening');
        $this->assertSame(['opening', 'sale', 'sale', 'payment'], array_column($statement['rows'], 'type'));

        // A period starting today carries everything before it as the opening balance.
        $later = app(CustomerStatementService::class)->build($this->org, $this->a, $this->owner, now()->addDay()->toDateString(), now()->addDays(2)->toDateString());
        $this->assertSame('1300.0000', $later['opening']);
        $this->assertSame([], $later['rows']);
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(1, DB::table('payments')->count(), 'the opening balance created no cash');
    }

    public function test_statement_is_viewer_scoped_and_exports_need_finance_export(): void
    {
        $this->order($this->a, '300.0000');
        $viewer = $this->member(['finance.view', 'customers.view']);
        $this->assertNull(app(CustomerStatementService::class)->build($this->org, $this->a, $viewer, '2026-01-01', now()->toDateString()));

        $this->actingAs($viewer)->get(route('finance.clients.accounts.statement', [$this->a, 'xlsx']))->assertForbidden();
        $this->actingAs($this->owner);
        $pdf = $this->get(route('finance.clients.accounts.statement', [$this->a, 'pdf', 'from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()]));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->get(route('finance.clients.accounts.statement', [$this->a, 'xlsx']))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_statement.exported']);
    }

    public function test_accounts_index_filters_sorts_and_exports_the_same_rows_server_side(): void
    {
        $this->order($this->a, '1000.0000');
        $this->order($this->b, '300.0000');
        $c = $this->createCustomer($this->org, 'Charlie');
        DB::table('customer_financial_terms')->insert(['organization_id' => $this->org->id, 'customer_id' => $this->b->id, 'financial_status' => 'blocked', 'policy' => 'warn',
            'credit_enabled' => false, 'overdue_tolerance_days' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $names = fn (array $query) => collect($this->get(route('finance.clients.accounts', $query))->assertOk()->viewData('page')['props']['customers']['data'])->pluck('display_name')->all();
        $this->assertSame(['Atlas', 'Bravo', 'Charlie'], $names(['sort' => 'unbilled', 'dir' => 'desc']));
        $this->assertSame(['Charlie', 'Bravo', 'Atlas'], $names(['sort' => 'unbilled', 'dir' => 'asc']));
        $this->assertSame(['Bravo'], $names(['financial_status' => 'blocked']));
        $this->assertSame(['Atlas'], $names(['search' => 'Atl']));
        $this->assertNotNull($c);

        $csv = $this->get(route('finance.clients.accounts.export', ['format' => 'csv', 'sort' => 'unbilled', 'dir' => 'desc']))->assertOk();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($csv->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('Atlas', $lines[1]);
        $this->assertStringContainsString('1000.0000', $lines[1], 'export shows exactly the page figure');
        $this->actingAs($this->member(['finance.view', 'customers.view']))->get(route('finance.clients.accounts.export', ['format' => 'csv']))->assertForbidden();
        $this->actingAs($this->owner);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_clients.accounts_exported']);
    }

    public function test_settlement_over_http_is_idempotent_authorized_and_audited(): void
    {
        $o1 = $this->order($this->a, '100.0000');
        $o2 = $this->order($this->a, '200.0000');
        $payload = ['client_operation_id' => (string) Str::uuid(), 'financial_account_id' => $this->account->id, 'method' => 'cash', 'amount' => '350.0000',
            'payment_date' => now()->toDateString(), 'allocations' => [['sales_order_id' => $o1->id, 'amount' => '100.0000'], ['sales_order_id' => $o2->id, 'amount' => '200.0000']]];
        $this->post(route('finance.clients.accounts.settlement.store', $this->a), $payload)->assertSessionHasNoErrors();
        $this->post(route('finance.clients.accounts.settlement.store', $this->a), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('50.0000', app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$this->a->id], $this->owner)[$this->a->id]['advances_available']);

        $preview = $this->postJson(route('finance.clients.accounts.settlement.preview', $this->a), ['amount' => '150.0000'])->assertOk()->json();
        $this->assertSame([], $preview['allocations'], 'nothing left to allocate once both orders are paid');
        $this->assertSame('150.0000', $preview['advance']);

        $reader = $this->member(['finance.view', 'customers.view', 'payments.create']);
        $this->actingAs($reader)->post(route('finance.clients.accounts.settlement.store', $this->a), $payload + [])->assertForbidden();
        $this->actingAs($reader)->post(route('finance.clients.accounts.opening.store', $this->a), ['effective_date' => now()->toDateString(), 'amount' => '1', 'currency_code' => 'MAD', 'reference' => 'x', 'reason' => 'y'])->assertForbidden();
        $this->actingAs($reader)->post(route('finance.clients.accounts.terms', $this->a), ['policy' => 'warn', 'financial_status' => 'normal'])->assertForbidden();
    }

    public function test_oldest_due_first_preview_orders_by_due_date_and_never_persists(): void
    {
        $late = $this->order($this->a, '100.0000');
        $early = $this->order($this->a, '100.0000');
        $undated = $this->order($this->a, '100.0000');
        DB::table('sales_orders')->where('id', $late->id)->update(['due_date' => now()->addDays(10)->toDateString()]);
        DB::table('sales_orders')->where('id', $early->id)->update(['due_date' => now()->subDays(10)->toDateString()]);
        DB::table('sales_orders')->where('id', $undated->id)->update(['due_date' => null]);

        $preview = $this->postJson(route('finance.clients.accounts.settlement.preview', $this->a), ['amount' => '250.0000'])->assertOk()->json();
        $this->assertSame([$early->id, $late->id, $undated->id], array_column($preview['allocations'], 'sales_order_id'));
        $this->assertSame(['100.0000', '100.0000', '50.0000'], array_column($preview['allocations'], 'amount'));
        $this->assertSame('0.0000', $preview['advance']);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_invoicing_sections_expose_unbilled_orders_credit_notes_and_exact_summary(): void
    {
        $order = $this->order($this->a, '640.0000');
        DB::table('sales_orders')->where('id', $order->id)->update(['due_date' => now()->subDays(3)->toDateString()]);

        $unbilled = $this->get(route('finance.clients.invoicing', ['section' => 'unbilled']))->assertOk()->viewData('page')['props']['unbilledOrders'];
        $this->assertSame($order->order_number, $unbilled['data'][0]['order_number']);
        $this->assertTrue($unbilled['data'][0]['is_overdue']);
        $this->assertSame('640.0000', $unbilled['data'][0]['outstanding']);

        $credit = $this->get(route('finance.clients.invoicing', ['section' => 'credit_notes']))->assertOk()->viewData('page')['props'];
        $this->assertSame(0, $credit['creditNotes']['total']);
        $this->assertSame(['gross', 'credit_notes', 'net'], array_keys($credit['summary']));
        $this->get(route('finance.clients.invoicing'))->assertOk()->assertInertia(fn ($page) => $page->where('creditNotes', null)->where('unbilledOrders', null));
        $this->get(route('finance.clients.receivables'))->assertOk()->assertInertia(fn ($page) => $page->has('orders.data.0.due_date')->where('orders.data.0.is_overdue', true));
    }

    public function test_opening_balance_reversal_and_terms_over_http_are_audited_and_listed_never_deleted(): void
    {
        $this->post(route('finance.clients.accounts.opening.store', $this->a), ['effective_date' => now()->toDateString(), 'amount' => '-120.0000', 'currency_code' => 'MAD', 'reference' => 'AVOIR-H', 'reason' => 'Crédit historique'])->assertSessionHasNoErrors();
        $props = $this->get(route('finance.clients.accounts.show', [$this->a, 'section' => 'terms']))->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $props['openings']);
        $this->assertSame('0.0000', app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$this->a->id], $this->owner)[$this->a->id]['credit_exposure'], 'a negative opening never offsets exposure');
        $id = (int) $props['openings'][0]->id;
        $this->post(route('finance.clients.accounts.opening.reverse', [$this->a, $id]), ['reason' => 'Erreur'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', DB::table('customer_opening_balances')->value('status'));
        $this->post(route('finance.clients.accounts.terms', $this->a), ['credit_enabled' => true, 'credit_limit' => '5000', 'policy' => 'block', 'financial_status' => 'watch', 'payment_term_days' => 30])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_financial_terms.saved']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_opening_balance.reversed']);
        $this->assertNotNull(app(ManageCustomerOpeningBalanceAction::class));
    }
}
