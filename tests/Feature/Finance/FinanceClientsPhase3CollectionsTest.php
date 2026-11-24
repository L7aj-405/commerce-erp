<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 3 / Recouvrement: reminders use the CURRENT business date; the historical aging date stays independent (FCUX-07). */
class FinanceClientsPhase3CollectionsTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    private FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 12:00:00');
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->customer = $this->createCustomer($this->org, 'Atlas');
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dueOrder(string $total, string $due): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => '2026-11-01']);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $total]);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        DB::table('sales_orders')->where('id', $order->id)->update(['due_date' => $due]);

        return $order->fresh();
    }

    private function reminders(string $month = '2026-11'): array
    {
        return $this->get(route('finance.clients.receivables', ['section' => 'reminders', 'month' => $month]))->assertOk()->viewData('page')['props'];
    }

    private function preview(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(route('finance.clients.reminders.preview', ['customer_id' => $this->customer->id, 'template' => 'overdue', 'language' => 'fr']));
    }

    public function test_customer_due_after_today_but_before_month_end_is_not_eligible_for_an_overdue_reminder(): void
    {
        $this->dueOrder('249.0000', '2026-11-26');           // overdue at 30 Nov (month-end filter) but NOT overdue today (24 Nov)

        $props = $this->reminders('2026-11');
        $this->assertSame('2026-11-24', $props['reminders']['businessDate']);
        $this->assertSame([], $props['reminders']['overdueCustomers'], 'eligibility uses the business date, not the month-end filter');

        // The historical aging date remains the reporting month end and still shows the debt as overdue there.
        $aging = $this->get(route('finance.clients.receivables', ['section' => 'aging', 'month' => '2026-11']))->assertOk()->viewData('page')['props'];
        $this->assertSame('2026-11-30', $aging['asOf']);
        $this->assertSame('249.0000', collect($aging['aging']['rows'])->firstWhere('customer_id', $this->customer->id)['overdue']);

        $this->preview()->assertStatus(422)->assertJsonPath('business_date', '2026-11-24');
        $this->post(route('finance.clients.reminders.follow_up'), ['customer_id' => $this->customer->id, 'template' => 'overdue', 'language' => 'fr'])->assertSessionHasErrors('customer_id');
        $this->assertSame(0, DB::table('follow_ups')->where('organization_id', $this->org->id)->count());
    }

    public function test_really_overdue_customer_is_eligible_and_preview_matches_the_listed_amount(): void
    {
        $this->dueOrder('249.0000', '2026-11-20');

        $listed = $this->reminders('2026-11')['reminders']['overdueCustomers'];
        $this->assertCount(1, $listed);
        $this->assertSame('249.0000', $listed[0]['overdue']);

        $ok = $this->preview()->assertOk();
        $this->assertStringContainsString('249', $ok->json('message'));
        $this->assertStringContainsString('4', $ok->json('message'), 'days overdue are counted from the business date');
        $this->assertSame('2026-11-24', $ok->json('business_date'));

        // Same answer whatever historical month the page is browsing.
        $this->assertSame($listed, $this->reminders('2026-10')['reminders']['overdueCustomers']);
        $this->post(route('finance.clients.reminders.follow_up'), ['customer_id' => $this->customer->id, 'template' => 'overdue', 'language' => 'fr'])->assertRedirect();
        $this->assertSame(1, DB::table('follow_ups')->where('organization_id', $this->org->id)->count());
    }

    public function test_zero_overdue_amount_never_produces_an_overdue_reminder(): void
    {
        $order = $this->dueOrder('249.0000', '2026-11-20');
        app(RecordCustomerSettlementAction::class)->execute($this->owner, $this->customer, [
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => '249.0000', 'payment_date' => '2026-11-24',
            'allocations' => [['sales_order_id' => $order->id, 'amount' => '249.0000']],
        ], (string) Str::uuid());

        $this->assertSame([], $this->reminders()['reminders']['overdueCustomers']);
        $this->preview()->assertStatus(422);
        $this->assertDatabaseMissing('follow_ups', ['organization_id' => $this->org->id]);
    }

    public function test_global_credit_policy_has_its_own_protected_page_and_risk_viewers_cannot_open_it(): void
    {
        $member = function (array $permissions) {
            $u = User::factory()->create();
            $this->addOrganizationMember($this->org, $u, $permissions, 'active', 'C'.Str::random(5));
            $this->addStoreMember($this->store, $u);
            $this->activate($u, $this->org, $this->store);

            return $u;
        };

        $viewer = $member(['finance.view', 'customers.view']);
        $this->actingAs($viewer)->get(route('finance.clients.credit'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Finance/Clients/Credit')->where('can.policy', false)->has('businessDate'));
        $this->actingAs($viewer)->get(route('customer-credit-settings.edit'))->assertForbidden();
        $this->actingAs($viewer)->post(route('finance.clients.credit.policy'), ['enforcement' => 'block'])->assertForbidden();

        $manager = $member(['finance.view', 'finance.customers.terms.manage']);
        $this->actingAs($manager)->get(route('customer-credit-settings.edit'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/CustomerCreditPolicy')->where('enforcement', 'off'));
        $this->actingAs($manager)->post(route('finance.clients.credit.policy'), ['enforcement' => 'warn', 'deduct_advances' => false])->assertSessionHasNoErrors();
        $this->actingAs($manager)->get(route('customer-credit-settings.edit'))->assertInertia(fn ($p) => $p->where('enforcement', 'warn'));
    }
}
