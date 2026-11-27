<?php

namespace Tests\Feature\Finance;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Finance\Customers\ManageCustomerAdvanceAction;
use App\Actions\Finance\Customers\ManageCustomerOpeningBalanceAction;
use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\CustomerInvoicingPolicy;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\CustomerAdvanceBalance;
use App\Services\Finance\CustomerFinanceDashboard;
use App\Services\Finance\CustomerFinancialPositionService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

/** Finance clients optimization batch 01: OPT-FC-001 .. OPT-FC-004 and FC4-FIN-01. */
class FinanceClientsOptimizationBatch01Test extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->actingAs($this->owner);
    }

    private function customer(string $name, string $type): Customer
    {
        return $this->createCustomer($this->org, $name, ['type' => $type, 'company_name' => $type === 'company' ? $name.' SARL' : null, 'tax_identifier' => 'ICE-1', 'billing_address' => 'Rue 1', 'email' => 'c@example.test', 'phone' => '0600000000']);
    }

    private function order(Customer $customer, string $total): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    private function position(Customer $customer): array
    {
        return app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$customer->id], $this->owner)[$customer->id];
    }

    private function issue(SalesOrder $order): void
    {
        $draft = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($this->owner, $order);
        app(IssueInvoiceAction::class)->execute($this->owner, $draft);
    }

    private function member(array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->org, $user, $permissions, 'active', 'R'.Str::random(5));
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->org, $this->store);

        return $user;
    }

    // ---------------------------------------------------------------- OPT-FC-004

    public function test_defaults_follow_the_customer_type_and_an_explicit_policy_overrides_them(): void
    {
        $this->assertSame(CustomerInvoicingPolicy::AfterFullPayment, CustomerInvoicingPolicy::defaultFor(null));
        $this->assertSame(CustomerInvoicingPolicy::AfterFullPayment, CustomerInvoicingPolicy::defaultFor(CustomerType::Individual));
        $this->assertSame(CustomerInvoicingPolicy::BeforePaymentAllowed, CustomerInvoicingPolicy::defaultFor(CustomerType::Company));
        $this->assertSame(['invoicing_policy'], ['invoicing_policy'], 'column exists');
        $this->assertTrue(Schema::hasColumn('customer_financial_terms', 'invoicing_policy'));
    }

    public function test_b2c_unpaid_invoice_issuance_is_rejected(): void
    {
        $order = $this->order($this->customer('Particulier', 'individual'), '1000.0000');
        $draft = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($this->owner, $order);

        try {
            app(IssueInvoiceAction::class)->execute($this->owner, $draft);
            $this->fail('B2C unpaid issuance must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame([IssueInvoiceAction::FULL_PAYMENT_REQUIRED_MESSAGE], $e->errors()['invoice']);
        }
        $this->assertSame('draft', $draft->fresh()->status->value);
    }

    public function test_b2c_fully_paid_invoice_issuance_is_accepted(): void
    {
        $order = $this->order($this->customer('Particulier', 'individual'), '1000.0000');
        $this->recordPayment($this->owner, $order, $this->account, (string) $order->total_incl_tax);
        $this->issue($order->fresh());
        $this->assertSame('issued', $order->invoices()->first()->status->value);
    }

    public function test_b2b_unpaid_invoice_issuance_is_accepted_without_creating_cash_revenue_or_duplicate_debt(): void
    {
        $customer = $this->customer('Entreprise', 'company');
        $order = $this->order($customer, '4000.0000');
        $total = (string) $order->total_incl_tax;
        $before = $this->position($customer);
        $this->assertSame('0.0000', $before['net_invoiced']);
        $this->assertSame('0.0000', $before['receivable']);
        $this->assertSame($total, $before['unbilled']);
        $this->assertSame($total, $before['total_to_collect']);
        $payments = Payment::query()->count();
        $orders = SalesOrder::query()->count();

        $this->issue($order);

        $after = $this->position($customer);
        $this->assertSame($total, $after['net_invoiced']);
        $this->assertSame($total, $after['receivable']);
        $this->assertSame('0.0000', $after['unbilled']);
        $this->assertSame($total, $after['total_to_collect'], 'no duplicate debt: invoiced replaces unbilled');
        $this->assertSame('0.0000', $after['net_collected'], 'no cash created');
        $this->assertSame($total, $after['net_sales'], 'no additional sale');
        $this->assertSame($payments, Payment::query()->count());
        $this->assertSame($orders, SalesOrder::query()->count());

        // Full payment afterwards clears the debt and records the collection once.
        $this->recordPayment($this->owner, $order->fresh(), $this->account, $total);
        $paid = $this->position($customer);
        $this->assertSame('0.0000', $paid['total_to_collect']);
        $this->assertSame('0.0000', $paid['receivable']);
        $this->assertSame($total, $paid['net_collected']);
    }

    public function test_b2b_partial_payment_invoice_keeps_the_correct_remaining_balance(): void
    {
        $customer = $this->customer('Entreprise', 'company');
        $order = $this->order($customer, '1000.0000');
        $total = (string) $order->total_incl_tax;
        $this->recordPayment($this->owner, $order, $this->account, '300.0000');
        $this->issue($order->fresh());
        $p = $this->position($customer);
        $this->assertSame(bcsub($total, '300', 4), $p['receivable']);
        $this->assertSame(bcsub($total, '300', 4), $p['total_to_collect']);
        $this->assertSame('300.0000', $p['net_collected']);
    }

    public function test_b2b_customer_pinned_to_payment_first_is_rejected_and_b2c_can_be_allowed(): void
    {
        $b2b = $this->customer('Entreprise', 'company');
        $b2c = $this->customer('Particulier', 'individual');
        $this->post(route('finance.clients.accounts.terms', $b2b), ['policy' => 'warn', 'financial_status' => 'normal', 'invoicing_policy' => 'after_full_payment'])->assertSessionHasNoErrors();
        $this->post(route('finance.clients.accounts.terms', $b2c), ['policy' => 'warn', 'financial_status' => 'normal', 'invoicing_policy' => 'before_payment_allowed'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_financial_terms.saved']);

        $draft = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($this->owner, $this->order($b2b, '500.0000'));
        $this->expectException(ValidationException::class);
        try {
            app(IssueInvoiceAction::class)->execute($this->owner, $draft);
        } finally {
            $this->assertSame('draft', $draft->fresh()->status->value);
            $this->issue($this->order($b2c, '500.0000'));
            $this->assertSame(1, Invoice::query()->where('status', 'issued')->count());
        }
    }

    public function test_policy_modification_requires_the_terms_permission_and_rejects_unknown_values(): void
    {
        $customer = $this->customer('Entreprise', 'company');
        $viewer = $this->member(['finance.view', 'customers.view']);
        $this->actingAs($viewer);
        $this->post(route('finance.clients.accounts.terms', $customer), ['policy' => 'warn', 'financial_status' => 'normal', 'invoicing_policy' => 'after_full_payment'])->assertForbidden();
        $this->assertDatabaseMissing('customer_financial_terms', ['customer_id' => $customer->id, 'invoicing_policy' => 'after_full_payment']);

        $this->actingAs($this->owner);
        $this->post(route('finance.clients.accounts.terms', $customer), ['policy' => 'warn', 'financial_status' => 'normal', 'invoicing_policy' => 'whatever'])->assertSessionHasErrors('invoicing_policy');
    }

    // ---------------------------------------------------------------- OPT-FC-001

    public function test_accounts_table_exposes_total_to_collect_and_overdue_and_sorts_by_them(): void
    {
        $small = $this->customer('Alpha', 'company');
        $big = $this->customer('Zulu', 'company');
        $this->order($small, '100.0000');
        $this->order($big, '900.0000');

        $props = $this->get(route('finance.clients.accounts', ['sort' => 'total_to_collect', 'dir' => 'desc']))->assertOk()->viewData('page')['props'];
        $names = array_column($props['customers']['data'], 'display_name');
        $this->assertSame(['Zulu', 'Alpha'], $names);
        $row = $props['customers']['data'][0]['position'];
        $this->assertSame($row['credit_exposure'], $row['total_to_collect']);
        $this->assertArrayHasKey('overdue', $row);
        $this->get(route('finance.clients.accounts', ['sort' => 'overdue']))->assertOk();
        $this->get(route('finance.clients.accounts', ['search' => 'Alpha']))->assertOk();
        $this->get(route('finance.clients.accounts', ['sort' => 'nope']))->assertSessionHasErrors('sort');
    }

    public function test_accounts_export_includes_the_new_totals_for_authorized_users(): void
    {
        $this->order($this->customer('Alpha', 'company'), '100.0000');
        $csv = $this->get(route('finance.clients.accounts.export', 'csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Total à encaisser', $csv);
        $this->assertStringContainsString('Facturé net', $csv, 'secondary columns remain exported');
    }

    // ---------------------------------------------------------------- OPT-FC-002

    public function test_summary_figures_reconcile_for_the_paid_invoiced_plus_paid_unbilled_case(): void
    {
        $customer = $this->customer('TEST B2C 01', 'individual');
        $first = $this->order($customer, '2499.0000');
        $this->recordPayment($this->owner, $first, $this->account, (string) $first->total_incl_tax);
        $this->issue($first->fresh());
        $second = $this->order($customer, '699.0000');
        $this->recordPayment($this->owner, $second, $this->account, (string) $second->total_incl_tax);

        $p = $this->position($customer);
        $sum = bcadd((string) $first->total_incl_tax, (string) $second->total_incl_tax, 4);
        $this->assertSame($sum, $p['net_sales']);
        $this->assertSame($sum, $p['net_collected']);
        $this->assertSame(bcadd((string) $first->total_incl_tax, '0', 4), $p['net_invoiced']);
        $this->assertSame('0.0000', $p['total_to_collect']);

        // The detail page serves exactly the table's authoritative figure.
        $show = $this->get(route('finance.clients.accounts.show', $customer))->assertOk()->viewData('page')['props']['position'];
        $row = $this->get(route('finance.clients.accounts'))->viewData('page')['props']['customers']['data'][0]['position'];
        $this->assertSame($row['total_to_collect'], $show['total_to_collect']);
    }

    public function test_net_collected_excludes_reversed_payments_and_does_not_count_advance_allocation_as_new_cash(): void
    {
        $customer = $this->customer('Atlas', 'individual');
        $order = $this->order($customer, '100.0000');
        $total = (string) $order->total_incl_tax;
        $advance = app(RecordCustomerSettlementAction::class)->execute($this->owner, $customer, [
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => $total, 'payment_date' => now()->toDateString(), 'allocations' => [],
        ], (string) Str::uuid());
        $this->assertSame($total, $this->position($customer)['net_collected'], 'advance received = cash once');
        app(ManageCustomerAdvanceAction::class)->allocate($this->owner, $advance->fresh(), [['sales_order_id' => $order->id, 'amount' => $total]]);
        $this->assertSame($total, $this->position($customer)['net_collected'], 'allocating an advance is not a new collection');
        $this->assertSame('0.0000', $this->position($customer)['total_to_collect']);
    }

    // ---------------------------------------------------------------- FC4-FIN-01

    public function test_available_advance_deducts_opening_balance_allocations_in_every_view(): void
    {
        $customer = $this->customer('Customer7', 'individual');
        $one = $this->order($customer, '30.0000');
        $two = $this->order($customer, '60.0000');
        $opening = app(ManageCustomerOpeningBalanceAction::class)->record($this->owner, $customer, ['effective_date' => now()->toDateString(), 'amount' => '30.0000', 'currency_code' => 'MAD', 'reference' => 'REP', 'reason' => 'Dette historique']);
        $advance = app(RecordCustomerSettlementAction::class)->execute($this->owner, $customer, [
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => '120.0000', 'payment_date' => now()->toDateString(), 'allocations' => [],
        ], (string) Str::uuid());
        $actions = app(ManageCustomerAdvanceAction::class);
        $actions->allocate($this->owner, $advance->fresh(), [['sales_order_id' => $one->id, 'amount' => (string) $one->total_incl_tax], ['sales_order_id' => $two->id, 'amount' => (string) $two->total_incl_tax]]);
        $left = bcsub('120', bcadd((string) $one->total_incl_tax, (string) $two->total_incl_tax, 4), 4);
        $actions->settleOpeningBalance($this->owner, $advance->fresh(), $opening, $left);

        $this->assertSame('0.0000', $this->position($customer)['advances_available']);
        $this->assertSame('0.0000', app(CustomerFinanceDashboard::class)->build($this->org, FinancePeriod::current($this->org), null)['balances']['advances']['MAD'] ?? '0.0000');
        $payments = $this->get(route('finance.clients.accounts.show', [$customer, 'section' => 'payments']))->assertOk()->viewData('page')['props']['payments'];
        $row = collect($payments)->firstWhere('id', $advance->id);
        $this->assertSame('0.0000', $row['advance_left'], 'Règlements shows the same exhausted advance');
        $this->assertSame('0.0000', app(ManageCustomerAdvanceAction::class)->available($advance->fresh()));
        $this->assertSame([$advance->id => $left], array_map(fn ($v) => $v, CustomerAdvanceBalance::openingAppliedByPayment($this->org->id, [$advance->id])));
    }
}
