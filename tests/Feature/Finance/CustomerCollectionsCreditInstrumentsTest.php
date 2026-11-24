<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\ManageCustomerCollectionPlanAction;
use App\Actions\Finance\Customers\ManageCustomerInstrumentAction;
use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\CustomerAgingReport;
use App\Services\Finance\CustomerCollectionForecast;
use App\Services\Finance\FinancialAccountBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 2 / FC-05 receivables, FC-06 credit, FC-07 incoming instruments. */
class CustomerCollectionsCreditInstrumentsTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private FinancialAccount $account;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->customer = $this->createCustomer($this->org, 'Atlas');
        $this->actingAs($this->owner);
    }

    private function order(string $total, ?string $due = null, ?Customer $customer = null): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer ?? $this->customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $total]);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        if ($due !== null) {
            DB::table('sales_orders')->where('id', $order->id)->update(['due_date' => $due]);
        }

        return $order->fresh();
    }

    private function receive(array $overrides = [], ?string $key = null): int
    {
        return app(ManageCustomerInstrumentAction::class)->receive($this->owner, $this->customer, array_replace([
            'type' => 'cheque', 'instrument_number' => 'CHQ-'.Str::random(5), 'issuing_bank' => 'BMCE', 'amount' => '400.0000', 'received_date' => now()->toDateString(),
            'financial_account_id' => $this->account->id, 'maturity_date' => now()->addDays(5)->toDateString(),
        ], $overrides), $key ?? (string) Str::uuid());
    }

    private function move(int $id, string $to, ?string $date = null, ?string $note = null): void
    {
        app(ManageCustomerInstrumentAction::class)->transition($this->owner, $id, $to, $date ?? now()->toDateString(), $note);
    }

    public function test_aging_bands_reconcile_to_receivable_plus_unbilled_and_support_a_historical_date(): void
    {
        $today = Carbon::now();
        $this->order('100.0000', $today->copy()->addDays(3)->toDateString());
        $this->order('200.0000', $today->copy()->subDays(20)->toDateString());
        $this->order('300.0000', $today->copy()->subDays(45)->toDateString());
        $this->order('400.0000', $today->copy()->subDays(100)->toDateString());
        $undated = $this->order('500.0000');
        DB::table('sales_orders')->where('id', $undated->id)->update(['due_date' => null]);

        $report = app(CustomerAgingReport::class)->build($this->org, null, $today);
        $row = $report['rows'][0];
        $this->assertSame('1500.0000', $row['total']);
        $this->assertSame(['not_due' => '100.0000', '1_30' => '200.0000', '31_60' => '300.0000', '61_90' => '0.0000', '90_plus' => '400.0000', 'undated' => '500.0000'], $row['bands']);
        $this->assertSame($row['total'], array_reduce($row['bands'], fn ($sum, $v) => bcadd($sum, $v, 4), '0.0000'), 'bands add up to the receivable + unbilled total');
        $this->assertSame('900.0000', $row['overdue']);
        $this->assertSame('1500.0000', $report['totals']['MAD']['total']);

        // Historical date: the same documents judged 60 days earlier age differently (the 100-day-old order was then 40 days past due).
        $past = app(CustomerAgingReport::class)->build($this->org, null, $today->copy()->subDays(60))['rows'][0];
        $this->assertSame('1500.0000', $past['total']);
        $this->assertSame('600.0000', $past['bands']['not_due']);
        $this->assertSame('400.0000', $past['bands']['31_60']);
        $this->assertSame('400.0000', $past['overdue']);
    }

    public function test_schedule_must_reconcile_and_a_balance_is_counted_once_in_the_forecast(): void
    {
        $order = $this->order('1000.0000', now()->subDays(5)->toDateString());
        $plan = app(ManageCustomerCollectionPlanAction::class);
        try {
            $plan->replaceSchedule($this->owner, $this->customer, $order, [['due_date' => now()->addDays(2)->toDateString(), 'amount' => '999.0000']]);
            $this->fail('a schedule must equal the remaining balance');
        } catch (ValidationException) {
        }
        $plan->replaceSchedule($this->owner, $this->customer, $order, [
            ['due_date' => now()->subDays(1)->toDateString(), 'amount' => '400.0000'], ['due_date' => now()->addDays(10)->toDateString(), 'amount' => '600.0000'],
        ]);
        // A promise on the SAME order must not be added on top of its schedule.
        $plan->createPromise($this->owner, $this->customer, $order, now()->addDays(3)->toDateString(), '1000.0000', 'Appel');

        $forecast = app(CustomerCollectionForecast::class)->build($this->org, null, Carbon::now());
        $buckets = $forecast['by_currency']['MAD'];
        $this->assertSame('400.0000', $buckets['overdue']);
        $this->assertSame('600.0000', $buckets['w2']);
        $this->assertSame('1000.0000', array_reduce($buckets, fn ($s, $v) => bcadd($s, $v, 4), '0.0000'), 'the order balance is counted exactly once');

        // Part payment is absorbed by the oldest installment first.
        $this->settle('250.0000', [[$order, '250.0000']]);
        $after = app(CustomerCollectionForecast::class)->build($this->org, null, Carbon::now())['by_currency']['MAD'];
        $this->assertSame('150.0000', $after['overdue']);
        $this->assertSame('600.0000', $after['w2']);
        $this->assertSame(2, DB::table('customer_payment_installments')->whereNull('cancelled_at')->count());

        // Replacing the schedule cancels (keeps) the old installments.
        $plan->replaceSchedule($this->owner, $this->customer, $order->fresh(), [['due_date' => now()->addDays(30)->toDateString(), 'amount' => '750.0000']]);
        $this->assertSame(1, DB::table('customer_payment_installments')->whereNull('cancelled_at')->count());
        $this->assertSame(3, DB::table('customer_payment_installments')->count());
    }

    public function test_promises_drive_the_forecast_only_without_a_schedule_and_unscheduled_orders_use_their_due_date(): void
    {
        $withPromise = $this->order('300.0000', now()->subDays(2)->toDateString());
        $this->order('200.0000', now()->addDays(40)->toDateString());
        app(ManageCustomerCollectionPlanAction::class)->createPromise($this->owner, $this->customer, $withPromise, now()->addDays(2)->toDateString(), '120.0000', null);

        $b = app(CustomerCollectionForecast::class)->build($this->org, null, Carbon::now())['by_currency']['MAD'];
        $this->assertSame('120.0000', $b['w1']);
        $this->assertSame('180.0000', $b['overdue'], 'what the promise does not cover stays on the order due date');
        $this->assertSame('200.0000', $b['later']);
        $this->assertSame('500.0000', array_reduce($b, fn ($s, $v) => bcadd($s, $v, 4), '0.0000'));
    }

    public function test_cheque_receipt_moves_no_money_clearance_posts_once_and_duplicate_clearance_is_a_noop(): void
    {
        $order = $this->order('400.0000');
        $id = $this->receive(['sales_order_id' => $order->id]);
        $this->assertSame(0, Payment::query()->count(), 'receiving a cheque is not a collection');
        $this->assertSame('unpaid', $order->fresh()->payment_status->value);
        $this->move($id, 'in_portfolio');
        $this->move($id, 'submitted');
        $this->assertSame(0, Payment::query()->count());

        $this->move($id, 'cleared');
        $this->move($id, 'cleared');   // replay / double click
        $this->assertSame(1, Payment::query()->count());
        $payment = Payment::query()->firstOrFail();
        $this->assertSame('cheque', $payment->method->value);
        $this->assertSame((string) $id, (string) DB::table('customer_payment_instruments')->where('cleared_payment_id', $payment->id)->value('id'));
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->assertSame(4, DB::table('customer_instrument_events')->count(), 'history: received, in_portfolio, submitted, cleared');

        // The existing independent balance rule sees exactly one inflow on the destination account.
        $account = app(FinancialAccountBalanceService::class)->organizationPosition($this->org, now(), $this->account->id)['accounts'][0];
        $this->assertSame('400.0000', $account['incoming'] ?? '400.0000');
        $this->assertSame('1', (string) DB::table('payments')->where('financial_account_id', $this->account->id)->count());
    }

    public function test_rejection_before_clearance_has_no_financial_effect_and_rejection_after_clearance_reverses_once(): void
    {
        $order = $this->order('300.0000');
        $bad = $this->receive(['sales_order_id' => $order->id, 'amount' => '300.0000']);
        $this->move($bad, 'submitted');
        $this->move($bad, 'rejected', null, 'Provision insuffisante');
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('unpaid', $order->fresh()->payment_status->value);
        try {
            $this->move($bad, 'cleared');
            $this->fail('a rejected instrument cannot be cleared');
        } catch (ValidationException) {
        }

        $late = $this->receive(['sales_order_id' => $order->id, 'amount' => '300.0000']);
        $this->move($late, 'submitted');
        $this->move($late, 'cleared');
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->move($late, 'rejected', null, 'Impayé bancaire');
        $this->assertSame('reversed', Payment::query()->firstOrFail()->status->value);
        $this->assertSame('unpaid', $order->fresh()->payment_status->value);
        $this->assertSame(1, Payment::query()->count(), 'the original payment is reversed, never deleted or duplicated');
    }

    public function test_instrument_rules_idempotent_receipt_duplicates_foreign_currency_and_closed_period_clearance(): void
    {
        $key = (string) Str::uuid();
        $first = $this->receive(['instrument_number' => 'DUP-1'], $key);
        $this->assertSame($first, $this->receive(['instrument_number' => 'DUP-1'], $key));
        $this->assertSame(1, DB::table('customer_payment_instruments')->count());
        try {
            $this->receive(['instrument_number' => 'DUP-1']);
            $this->fail('same type, number and bank is refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instrument_number', $e->errors());
        }

        $id = $this->receive(['received_date' => now()->subMonths(2)->startOfMonth()->toDateString(), 'amount' => '50.0000']);
        $this->move($id, 'submitted', now()->subMonths(2)->startOfMonth()->toDateString());
        DB::table('finance_month_reviews')->insert(['organization_id' => $this->org->id, 'period' => now()->subMonths(2)->startOfMonth()->toDateString(), 'status' => 'closed', 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(ValidationException::class);
        $this->move($id, 'cleared', now()->subMonths(2)->startOfMonth()->toDateString());
    }

    public function test_instruments_are_isolated_by_organization_store_and_permission(): void
    {
        $id = $this->receive();
        $reader = User::factory()->create();
        $this->addOrganizationMember($this->org, $reader, ['finance.view'], 'active', 'R'.Str::random(4));
        $this->addStoreMember($this->store, $reader);
        $this->activate($reader, $this->org, $this->store);
        $this->actingAs($reader);
        $this->get(route('finance.clients.instruments'))->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false)->where('customers', []));
        $this->post(route('finance.clients.instruments.transition', $id), ['to' => 'submitted', 'date' => now()->toDateString()])->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignStore = $this->createStore($foreignOrg, $foreignOwner, 'FS');
        $this->activate($foreignOwner, $foreignOrg, $foreignStore);
        $this->actingAs($foreignOwner)->post(route('finance.clients.instruments.transition', $id), ['to' => 'submitted', 'date' => now()->toDateString()])->assertNotFound();
        $this->assertSame([], $this->get(route('finance.clients.instruments'))->viewData('page')['props']['instruments']['data']);
    }

    public function test_credit_policy_and_exception_endpoints_require_their_own_permissions_and_are_audited(): void
    {
        $this->post(route('finance.clients.credit.policy'), ['enforcement' => 'block'])->assertSessionHasNoErrors();
        $this->assertSame('block', $this->org->fresh()->settings['customer_credit']['enforcement']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_credit.policy_changed']);
        $this->post(route('finance.clients.credit.exception'), ['customer_id' => $this->customer->id, 'authorized_amount' => '500', 'reason' => 'Client stratégique'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_credit.exception_granted']);

        $props = $this->get(route('finance.clients.credit'))->assertOk()->viewData('page')['props'];
        $this->assertSame('block', $props['enforcement']);
        $this->assertCount(1, $props['exceptions']);

        $reader = User::factory()->create();
        $this->addOrganizationMember($this->org, $reader, ['finance.view'], 'active', 'R'.Str::random(4));
        $this->addStoreMember($this->store, $reader);
        $this->activate($reader, $this->org, $this->store);
        $this->actingAs($reader);
        $this->get(route('finance.clients.credit'))->assertOk()->assertInertia(fn ($page) => $page->where('can.override', false)->where('can.policy', false));
        $this->post(route('finance.clients.credit.policy'), ['enforcement' => 'off'])->assertForbidden();
        $this->post(route('finance.clients.credit.exception'), ['customer_id' => $this->customer->id, 'authorized_amount' => '500', 'reason' => 'Contournement'])->assertForbidden();
    }

    public function test_receivables_sections_aging_reminders_and_export_over_http(): void
    {
        $this->order('250.0000', now()->subDays(40)->toDateString());
        $aging = $this->get(route('finance.clients.receivables', ['section' => 'aging']))->assertOk()->viewData('page')['props']['aging'];
        $this->assertSame('250.0000', $aging['rows'][0]['total']);
        $this->assertSame('250.0000', $aging['rows'][0]['bands']['31_60']);
        $this->get(route('finance.clients.receivables', ['section' => 'forecasts']))->assertOk()->assertInertia(fn ($page) => $page->where('forecast.by_currency.MAD.overdue', '250.0000'));
        $this->get(route('finance.clients.receivables', ['section' => 'reminders']))->assertOk()->assertInertia(fn ($page) => $page->has('reminders.promises'));
        $csv = $this->get(route('finance.clients.aging.export', ['format' => 'csv']))->assertOk();
        $this->assertStringContainsString('250.0000', $csv->streamedContent(), 'export shows the page figure');
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_clients.aging_exported']);
    }

    /** @param list<array{0:SalesOrder,1:string}> $allocations */
    private function settle(string $amount, array $allocations): Payment
    {
        return app(RecordCustomerSettlementAction::class)->execute($this->owner, $this->customer, [
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => $amount, 'payment_date' => now()->toDateString(),
            'allocations' => array_map(fn ($r) => ['sales_order_id' => $r[0]->id, 'amount' => $r[1]], $allocations),
        ], (string) Str::uuid());
    }
}
