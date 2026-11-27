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

/** Finance clients Phase 3: none of the new pages or actions is available in Test Mode, and nothing is created there. */
class FinanceClientsPhase3TestModeTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    private FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->customer = $this->createCustomer($this->org, 'Atlas');
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->actingAs($this->owner);
    }

    /** Refused = a non-200 answer, or the Test Mode guard refusing production tables (it throws). */
    private function refused(callable $request): bool
    {
        try {
            return $request()->getStatusCode() !== 200;
        } catch (Throwable) {
            return true;
        }
    }

    public function test_new_pages_and_actions_are_refused_in_test_mode_and_create_nothing(): void
    {
        $this->get(route('finance.clients.advances'))->assertOk();   // reachable in Production
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();

        foreach (['finance.clients.advances', 'finance.clients.instruments', 'finance.clients.credit', 'customer-credit-settings.edit', 'finance.clients.collections', 'finance.clients.receivables', 'finance.clients.reports'] as $route) {
            $this->assertTrue($this->refused(fn () => $this->get(route($route))), $route.' must not render in Test Mode');
        }
        foreach (['finance.clients.advances.export', 'finance.clients.instruments.export'] as $route) {
            $this->assertTrue($this->refused(fn () => $this->get(route($route, 'csv'))), $route.' must not export in Test Mode');
        }

        $this->refused(fn () => $this->post(route('finance.clients.accounts.settlement.store', $this->customer), [
            'client_operation_id' => (string) Str::uuid(), 'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => '50.0000', 'payment_date' => now()->toDateString(),
        ]));
        $this->refused(fn () => $this->post(route('finance.clients.instruments.store'), [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'type' => 'cheque', 'instrument_number' => 'TM-1', 'amount' => '10', 'received_date' => now()->toDateString(), 'financial_account_id' => $this->account->id,
        ]));
        $this->refused(fn () => $this->post(route('finance.clients.credit.policy'), ['enforcement' => 'block']));
        $this->refused(fn () => $this->post(route('finance.clients.reminders.follow_up'), ['customer_id' => $this->customer->id, 'template' => 'overdue', 'language' => 'fr']));

        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $this->assertSame(0, DB::table('payments')->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, DB::table('customer_payment_instruments')->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, DB::table('follow_ups')->where('organization_id', $this->org->id)->count());
        $this->assertNotSame('block', data_get($this->org->fresh()->settings, 'customer_credit.enforcement'));
    }
}
