<?php

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 3 security regressions: moving a function under a new parent never grants (or removes) its own permission. */
class FinanceClientsPhase3SecurityTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    private FinancialAccount $cashOnly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->customer = $this->createCustomer($this->org, 'Atlas');
        $this->cashOnly = $this->createFinancialAccount($this->org, ['type' => 'cash', 'accepted_methods' => ['cash']]);
        $this->actingAs($this->owner);
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->org, $user, $permissions, 'active', 'S'.Str::random(5));
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->org, $this->store);

        return $user;
    }

    public function test_settlement_with_a_method_the_account_does_not_accept_is_refused_and_creates_nothing(): void
    {
        $this->post(route('finance.clients.accounts.settlement.store', $this->customer), [
            'client_operation_id' => (string) Str::uuid(), 'financial_account_id' => $this->cashOnly->id, 'method' => 'bank_transfer', 'amount' => '100.0000', 'payment_date' => now()->toDateString(),
        ])->assertSessionHasErrors();
        $this->assertSame(0, DB::table('payments')->where('organization_id', $this->org->id)->count());

        $this->post(route('finance.clients.accounts.settlement.store', $this->customer), [
            'client_operation_id' => (string) Str::uuid(), 'financial_account_id' => $this->cashOnly->id, 'method' => 'cash', 'amount' => '100.0000', 'payment_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('payments')->where('organization_id', $this->org->id)->count());
    }

    public function test_every_export_needs_finance_export_even_for_a_viewer_of_the_page(): void
    {
        $viewer = $this->member(['finance.view', 'customers.view', 'payments.view']);
        $this->actingAs($viewer);
        foreach (['finance.clients.accounts.export', 'finance.clients.aging.export', 'finance.clients.journal.export', 'finance.clients.profitability.export', 'finance.clients.advances.export'] as $route) {
            $this->get(route($route, 'csv'))->assertForbidden();
        }
        $this->get(route('finance.clients.accounts.statement', [$this->customer, 'pdf']))->assertForbidden();
    }

    public function test_users_without_finance_view_cannot_reach_any_relocated_page_but_keep_their_payments_entry(): void
    {
        $cashier = $this->member(['payments.view', 'customers.view']);
        $this->actingAs($cashier);
        foreach (['finance.clients.advances', 'finance.clients.instruments', 'finance.clients.credit', 'finance.clients.journal', 'finance.clients.reports', 'finance.clients.collections', 'finance.clients.receivables'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get('/payments')->assertOk();
    }

    public function test_credit_and_instrument_mutations_keep_their_own_permissions_under_the_new_parent(): void
    {
        $viewer = $this->member(['finance.view', 'customers.view']);
        $this->actingAs($viewer);
        $this->post(route('finance.clients.credit.exception'), ['customer_id' => $this->customer->id, 'authorized_amount' => '10', 'reason' => 'Contournement'])->assertForbidden();
        $this->post(route('finance.clients.credit.policy'), ['enforcement' => 'block'])->assertForbidden();
        $this->post(route('finance.clients.instruments.store'), [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'type' => 'cheque', 'instrument_number' => 'X1', 'amount' => '10', 'received_date' => now()->toDateString(), 'financial_account_id' => $this->cashOnly->id,
        ])->assertForbidden();
        $this->assertSame(0, DB::table('customer_payment_instruments')->count());
    }

    public function test_foreign_organization_customer_cannot_be_settled_or_allocated(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignCustomer = $this->createCustomer($foreignOrg, 'Foreign Customer');
        $this->post(route('finance.clients.accounts.settlement.store', $foreignCustomer), [
            'client_operation_id' => (string) Str::uuid(), 'financial_account_id' => $this->cashOnly->id, 'method' => 'cash', 'amount' => '10.0000', 'payment_date' => now()->toDateString(),
        ])->assertNotFound();
        $this->assertSame(0, DB::table('payments')->count());
    }
}
