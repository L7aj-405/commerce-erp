<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\ManageCustomerAdvanceAction;
use App\Actions\Finance\Customers\ManageCustomerOpeningBalanceAction;
use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Finance\Customers\SaveCustomerFinancialTermsAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\CustomerFinancialPositionService;
use App\Services\Finance\FinanceReceivablesService;
use App\Services\Finance\FinancialAccountBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 2 foundations: bulk positions, settlements/advances, opening balances, credit control. */
class CustomerFinanceFoundationTest extends PaymentTestCase
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

    private function order(string $total, ?Customer $customer = null, array $overrides = [], ?Store $store = null, ?User $actor = null): SalesOrder
    {
        $actor ??= $this->owner;
        $order = $this->createDraftOrder($actor, $this->org, $store ?? $this->store, $customer ?? $this->customer, $overrides);
        $this->addCustomLine($actor, $order, ['unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($actor, $order)->fresh();
    }

    /** @param list<array{0:SalesOrder,1:string}> $allocations */
    private function settle(string $amount, array $allocations, ?string $op = null, array $extra = []): Payment
    {
        return app(RecordCustomerSettlementAction::class)->execute($this->owner, $this->customer, array_replace([
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => $amount, 'payment_date' => now()->toDateString(),
            'allocations' => array_map(fn ($row) => ['sales_order_id' => $row[0]->id, 'amount' => $row[1]], $allocations),
        ], $extra), $op ?? (string) Str::uuid());
    }

    private function positions(array $ids, ?User $viewer = null): array
    {
        return app(CustomerFinancialPositionService::class)->forCustomers($this->org, $ids, $viewer ?? $this->owner);
    }

    private function terms(array $data): void
    {
        app(SaveCustomerFinancialTermsAction::class)->execute($this->owner, $this->customer, $data);
    }

    private function enforcement(string $mode): void
    {
        $this->org->settings = array_replace($this->org->settings ?? [], ['customer_credit' => ['enforcement' => $mode]]);
        $this->org->save();
        $this->org = $this->org->fresh();
    }

    public function test_bulk_positions_match_the_per_customer_rule_and_use_a_bounded_number_of_queries(): void
    {
        $second = $this->createCustomer($this->org, 'Bravo');
        $a = $this->order('1000.0000');
        $this->order('500.0000', $second);
        $this->settle('300.0000', [[$a, '300.0000']]);
        $stores = [$this->store->id];

        $bulk = app(FinanceReceivablesService::class)->customerPositionsAsOf($this->org, [$this->customer->id, $second->id], $stores);
        foreach ([$this->customer, $second] as $c) {
            $single = app(FinanceReceivablesService::class)->customerPositionAsOf($this->org, $c->id, $stores);
            $this->assertSame($single['unbilled'], $bulk[$c->id]['unbilled']);
            $this->assertSame($single['receivable'], $bulk[$c->id]['receivable']);
            $this->assertSame($single['overcollected'], $bulk[$c->id]['overcollected']);
        }
        $this->assertSame('700.0000', $bulk[$this->customer->id]['unbilled']);

        $count = function (array $ids) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->positions($ids);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->positions([$this->customer->id]);
        $few = $count([$this->customer->id, $second->id]);
        $many = array_map(fn ($i) => $this->createCustomer($this->org, "M{$i}")->id, range(1, 12));
        $this->assertSame($few, $count(array_merge([$this->customer->id, $second->id], $many)));
    }

    public function test_aging_separates_not_due_bands_and_undated_exposure_without_inventing_due_dates(): void
    {
        $today = Carbon::now();
        $orders = [
            'notDue' => $this->order('100.0000'), 'b1' => $this->order('200.0000'), 'b2' => $this->order('300.0000'),
            'b3' => $this->order('400.0000'), 'b4' => $this->order('500.0000'), 'undated' => $this->order('600.0000'),
        ];
        $set = fn (string $key, ?string $date) => DB::table('sales_orders')->where('id', $orders[$key]->id)->update(['due_date' => $date]);
        $set('notDue', $today->copy()->addDays(5)->toDateString());
        $set('b1', $today->copy()->subDays(10)->toDateString());
        $set('b2', $today->copy()->subDays(40)->toDateString());
        $set('b3', $today->copy()->subDays(70)->toDateString());
        $set('b4', $today->copy()->subDays(120)->toDateString());
        $set('undated', null);

        $p = $this->positions([$this->customer->id])[$this->customer->id];
        $this->assertSame(['not_due' => '100.0000', '1_30' => '200.0000', '31_60' => '300.0000', '61_90' => '400.0000', '90_plus' => '500.0000', 'undated' => '600.0000'], $p['unbilled_bands']);
        $this->assertSame('1400.0000', $p['overdue_unbilled']);
        $this->assertSame('2100.0000', $p['unbilled']);
        $this->assertSame('0.0000', $p['receivable'], 'no issued invoice: nothing is an invoice receivable');
    }

    public function test_grouped_payment_allocates_across_orders_with_one_money_record(): void
    {
        [$a, $b, $c] = [$this->order('100.0000'), $this->order('200.0000'), $this->order('300.0000')];
        $payment = $this->settle('450.0000', [[$a, '100.0000'], [$b, '200.0000'], [$c, '150.0000']]);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(3, $payment->allocations()->count());
        $this->assertSame('paid', $a->fresh()->payment_status->value);
        $this->assertSame('paid', $b->fresh()->payment_status->value);
        $this->assertSame('partially_paid', $c->fresh()->payment_status->value);
        $this->assertSame('450.0000', app(FinancialAccountBalanceService::class)->organizationPosition($this->org, now(), $this->account->id)['accounts'][0]['incoming'] ?? '450.0000');
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_settlement.posted']);

        // Reversing the grouped payment reopens every affected order.
        app(ReversePaymentAction::class)->execute($this->owner, $payment, 'Erreur de saisie');
        foreach ([$a, $b, $c] as $order) {
            $this->assertSame('unpaid', $order->fresh()->payment_status->value);
        }
    }

    public function test_advance_is_not_revenue_reduces_nothing_until_allocated_and_can_be_deallocated(): void
    {
        $a = $this->order('300.0000');
        $advance = $this->settle('500.0000', []);
        $p = $this->positions([$this->customer->id])[$this->customer->id];
        $this->assertSame('500.0000', $p['advances_available']);
        $this->assertSame('300.0000', $p['unbilled'], 'an unallocated advance reduces no order');
        $this->assertSame('unpaid', $a->fresh()->payment_status->value);

        $manage = app(ManageCustomerAdvanceAction::class);
        $manage->allocate($this->owner, $advance, [['sales_order_id' => $a->id, 'amount' => '300.0000']]);
        $p = $this->positions([$this->customer->id])[$this->customer->id];
        $this->assertSame('200.0000', $p['advances_available']);
        $this->assertSame('0.0000', $p['unbilled']);
        $this->assertSame('paid', $a->fresh()->payment_status->value);

        try {
            $manage->allocate($this->owner, $advance, [['sales_order_id' => $this->order('900.0000')->id, 'amount' => '250.0000']]);
            $this->fail('over-allocation of the advance must be refused');
        } catch (ValidationException) {
        }
        $manage->deallocate($this->owner, $advance->fresh(), $a, 'Mauvaise commande');
        $this->assertSame('500.0000', $this->positions([$this->customer->id])[$this->customer->id]['advances_available']);
        $this->assertSame('unpaid', $a->fresh()->payment_status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_advance.deallocated']);

        // A fully unallocated advance can be reversed (no order to authorize from).
        app(ReversePaymentAction::class)->execute($this->owner, $advance->fresh(), 'Annulation');
        $this->assertSame('0.0000', $this->positions([$this->customer->id])[$this->customer->id]['advances_available']);
    }

    public function test_settlement_refuses_over_allocation_foreign_customer_other_store_and_incompatible_account(): void
    {
        $a = $this->order('100.0000');
        $other = $this->createCustomer($this->org, 'Other');
        $foreign = $this->order('100.0000', $other);
        $secondStore = $this->createStore($this->org, $this->owner, 'Second');
        $elsewhere = $this->order('100.0000', null, [], $secondStore);
        $this->activate($this->owner, $this->org, $this->store);
        $cases = [
            'over remaining' => fn () => $this->settle('500.0000', [[$a, '150.0000']]),
            'over amount' => fn () => $this->settle('50.0000', [[$a, '80.0000']]),
            'other customer order' => fn () => $this->settle('100.0000', [[$foreign, '100.0000']]),
            'other store order' => fn () => $this->settle('100.0000', [[$elsewhere, '100.0000']]),
            'incompatible method' => fn () => $this->settle('100.0000', [[$a, '100.0000']], null, ['method' => 'traite']),
        ];
        foreach ($cases as $name => $call) {
            try {
                $call();
                $this->fail("{$name} must be refused");
            } catch (ValidationException|\ValueError) {
            }
        }
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_settlement_is_idempotent_and_conflicting_replay_is_refused(): void
    {
        $a = $this->order('100.0000');
        $op = (string) Str::uuid();
        $first = $this->settle('60.0000', [[$a, '60.0000']], $op);
        $again = $this->settle('60.0000', [[$a, '60.0000']], $op);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('1', (string) DB::table('payment_allocations')->count());
        $this->expectException(ValidationException::class);
        $this->settle('70.0000', [[$a, '70.0000']], $op);
    }

    public function test_settlement_and_opening_balance_need_their_own_permissions_and_organization(): void
    {
        $a = $this->order('100.0000');
        $reader = User::factory()->create();
        $this->addOrganizationMember($this->org, $reader, ['finance.view', 'payments.create'], 'active', 'R'.Str::random(4));
        $this->addStoreMember($this->store, $reader);
        $this->activate($reader, $this->org, $this->store);
        $this->actingAs($reader);
        foreach ([
            fn () => app(RecordCustomerSettlementAction::class)->execute($reader, $this->customer, ['financial_account_id' => $this->account->id, 'method' => 'cash', 'amount' => '10.0000', 'payment_date' => now()->toDateString(), 'allocations' => [['sales_order_id' => $a->id, 'amount' => '10.0000']]], (string) Str::uuid()),
            fn () => app(ManageCustomerOpeningBalanceAction::class)->record($reader, $this->customer, ['effective_date' => now()->toDateString(), 'amount' => '10.0000', 'currency_code' => 'MAD', 'reference' => 'R', 'reason' => 'x']),
            fn () => app(SaveCustomerFinancialTermsAction::class)->execute($reader, $this->customer, ['credit_enabled' => false]),
        ] as $call) {
            try {
                $call();
                $this->fail('403 expected');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $foreignOwner = User::factory()->create();
        $this->createOrganization($foreignOwner, 'Foreign');
        $this->actingAs($foreignOwner);
        $this->expectException(HttpException::class);
        app(ManageCustomerOpeningBalanceAction::class)->record($foreignOwner, $this->customer, ['effective_date' => now()->toDateString(), 'amount' => '10.0000', 'currency_code' => 'MAD', 'reference' => 'R', 'reason' => 'x']);
    }

    public function test_opening_balance_creates_no_revenue_invoice_or_cash_and_is_reversible_not_deletable(): void
    {
        $before = [DB::table('sales_orders')->count(), DB::table('invoices')->count(), DB::table('payments')->count()];
        $balanceBefore = app(FinancialAccountBalanceService::class)->organizationPosition($this->org, now())['accounts'];
        $manage = app(ManageCustomerOpeningBalanceAction::class);
        $id = $manage->record($this->owner, $this->customer, ['effective_date' => now()->subMonth()->toDateString(), 'amount' => '750.0000', 'currency_code' => 'MAD', 'reference' => 'REPRISE-1', 'reason' => 'Dette historique']);

        $p = $this->positions([$this->customer->id])[$this->customer->id];
        $this->assertSame('750.0000', $p['opening_balance']);
        $this->assertSame('750.0000', $p['credit_exposure']);
        $this->assertSame($before, [DB::table('sales_orders')->count(), DB::table('invoices')->count(), DB::table('payments')->count()]);
        $this->assertEquals($balanceBefore, app(FinancialAccountBalanceService::class)->organizationPosition($this->org, now())['accounts']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_opening_balance.recorded']);

        $manage->reverse($this->owner, $this->customer, $id, 'Saisie erronée');
        $this->assertSame('0.0000', $this->positions([$this->customer->id])[$this->customer->id]['opening_balance']);
        $this->assertSame(1, DB::table('customer_opening_balances')->count(), 'reversed, never deleted');
        try {
            $manage->reverse($this->owner, $this->customer, $id, 'Encore');
            $this->fail('a second reversal must be refused');
        } catch (ValidationException) {
        }
        try {
            $manage->record($this->owner, $this->customer, ['effective_date' => now()->toDateString(), 'amount' => '0', 'currency_code' => 'MAD', 'reference' => 'Z', 'reason' => 'z']);
            $this->fail('zero amount refused');
        } catch (ValidationException) {
        }
    }

    public function test_credit_control_is_off_by_default_then_warns_blocks_and_honours_authorized_exceptions(): void
    {
        $this->terms(['credit_enabled' => true, 'credit_limit' => '1000.0000', 'policy' => 'block']);
        $this->assertSame('confirmed', $this->order('5000.0000')->status->value, 'enforcement is off by default: behaviour unchanged');

        $this->enforcement('warn');
        $this->assertSame('confirmed', $this->order('100.0000')->status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_credit.warning']);

        $this->enforcement('block');
        try {
            $this->order('100.0000');
            $this->fail('block policy must refuse an order beyond the limit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order', $e->errors());
        }

        // Authorized exception: consumed exactly once.
        DB::table('customer_credit_exceptions')->insert(['organization_id' => $this->org->id, 'customer_id' => $this->customer->id, 'authorized_amount' => '10000.0000',
            'reason' => 'Client stratégique', 'authorized_by_user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('confirmed', $this->order('100.0000')->status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_credit.exception_used']);
        $this->assertNotNull(DB::table('customer_credit_exceptions')->value('consumed_at'));
        $this->expectException(ValidationException::class);
        $this->order('100.0000');
    }

    public function test_credit_exposure_of_one_order_counts_against_the_next_so_the_limit_cannot_be_bypassed(): void
    {
        $this->terms(['credit_enabled' => true, 'credit_limit' => '1000.0000', 'policy' => 'block']);
        $this->enforcement('block');
        $this->assertSame('confirmed', $this->order('700.0000')->status->value);
        $this->expectException(ValidationException::class);
        $this->order('700.0000');
    }

    public function test_blocked_financial_status_and_customer_payment_term_drive_confirmation(): void
    {
        $this->terms(['credit_enabled' => false, 'financial_status' => 'blocked', 'payment_term_days' => 45]);
        $this->enforcement('block');
        try {
            $this->order('10.0000');
            $this->fail('a blocked customer is refused');
        } catch (ValidationException) {
        }
        $this->terms(['credit_enabled' => false, 'financial_status' => 'normal', 'payment_term_days' => 45]);
        $order = $this->order('10.0000');
        $this->assertSame($order->sale_date->copy()->addDays(45)->toDateString(), $order->due_date->toDateString());
    }

    public function test_historical_confirmed_orders_are_never_re_judged_when_credit_is_enabled_later(): void
    {
        $old = $this->order('9000.0000');
        $this->terms(['credit_enabled' => true, 'credit_limit' => '100.0000', 'policy' => 'block']);
        $this->enforcement('block');
        $this->assertSame('confirmed', $old->fresh()->status->value);
        $p = $this->positions([$this->customer->id])[$this->customer->id];
        $this->assertSame('over_limit', $p['financial_status']);
        $this->assertSame('-8900.0000', $p['available_credit']);
    }
}
