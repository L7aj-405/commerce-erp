<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\ManageCustomerAdvanceAction;
use App\Actions\Finance\Customers\ManageCustomerInstrumentAction;
use App\Actions\Finance\Customers\ManageCustomerOpeningBalanceAction;
use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Finance\Customers\SaveCustomerFinancialTermsAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\RemoveSalesOrderLineAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\CustomerAgingReport;
use App\Services\Finance\CustomerCreditGuard;
use App\Services\Finance\CustomerFinancialPositionService;
use App\Services\Finance\CustomerReminderTemplates;
use App\Services\Finance\CustomerStatementService;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReceivablesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 2 hardening: dated advance allocations, idempotency fingerprint, opening settlement, credit control, statements. */
class CustomerFinanceHardeningTest extends PaymentTestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $total, ?string $saleDate = null): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, $saleDate ? ['sale_date' => $saleDate] : []);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    private function settle(string $amount, array $allocations = [], ?string $op = null, array $extra = []): Payment
    {
        return app(RecordCustomerSettlementAction::class)->execute($this->owner, $this->customer, array_replace([
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => $amount, 'payment_date' => now()->toDateString(),
            'allocations' => array_map(fn ($row) => ['sales_order_id' => $row[0]->id, 'amount' => $row[1]], $allocations),
        ], $extra), $op ?? (string) Str::uuid());
    }

    private function closeMonth(string $month): void
    {
        DB::table('finance_month_reviews')->insert(['organization_id' => $this->org->id, 'period' => $month.'-01', 'status' => 'closed', 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function position(?string $asOf = null): array
    {
        return app(FinanceReceivablesService::class)->customerPositionsAsOf($this->org, [$this->customer->id], [$this->store->id], $asOf ? Carbon::parse($asOf) : null)[$this->customer->id] ?? [];
    }

    public function test_advance_received_in_a_closed_period_can_be_allocated_in_an_open_one_without_touching_the_closed_period(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $advance = $this->settle('500.0000');
        $this->assertSame('2026-11-10', substr((string) $advance->fresh()->getRawOriginal('payment_date'), 0, 10));
        $cashBefore = DB::table('payments')->where('organization_id', $this->org->id)->sum('amount');

        Carbon::setTestNow('2026-12-05 10:00:00');
        $this->closeMonth('2026-11');
        $order = $this->order('800.0000', '2026-12-05');

        $actions = app(ManageCustomerAdvanceAction::class);
        try {
            $actions->allocate($this->owner, $advance, [['sales_order_id' => $order->id, 'amount' => '500.0000']], '2026-11-20');
            $this->fail('An effective date inside a closed period must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_date', $e->errors());
        }
        try {
            $actions->allocate($this->owner, $advance, [['sales_order_id' => $order->id, 'amount' => '500.0000']], '2026-11-05');
            $this->fail('An effective date before the receipt must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_date', $e->errors());
        }

        $actions->allocate($this->owner, $advance, [['sales_order_id' => $order->id, 'amount' => '500.0000']]);

        // The receipt is untouched and no second receipt exists (no double-counted treasury).
        $this->assertSame('2026-11-10', substr((string) $advance->fresh()->getRawOriginal('payment_date'), 0, 10));
        $this->assertSame(1, DB::table('payments')->where('organization_id', $this->org->id)->count());
        $this->assertEquals($cashBefore, DB::table('payments')->where('organization_id', $this->org->id)->sum('amount'));
        $this->assertSame('2026-12-05', substr((string) DB::table('payment_allocations')->where('payment_id', $advance->id)->value('effective_date'), 0, 10));

        // November position unchanged: no December order, nothing collected against orders yet; December receivable is settled by the advance.
        $this->assertSame([], array_filter($this->position('2026-11-30'), fn ($v) => is_string($v) && $v !== '0.0000' && is_numeric($v)));
        $december = $this->position('2026-12-05');
        $this->assertSame('300.0000', $december['unbilled']);
        $this->assertSame('300.0000', app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$this->customer->id], $this->owner)[$this->customer->id]['unbilled']);
        $this->assertSame('0.0000', app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$this->customer->id], $this->owner)[$this->customer->id]['advances_available']);

        // Statements: November closes with the advance as a credit; December applies it with a neutral advance/allocation pair.
        $statements = app(CustomerStatementService::class);
        $nov = $statements->build($this->org, $this->customer, $this->owner, '2026-11-01', '2026-11-30');
        $this->assertSame('-500.0000', $nov['closing']);
        $this->assertSame('500.0000', $nov['breakdown']['advances_available']);
        $dec = $statements->build($this->org, $this->customer, $this->owner, '2026-12-01', '2026-12-31');
        $this->assertSame('-500.0000', $dec['opening']);
        $this->assertSame('300.0000', $dec['closing']);
        $this->assertSame('0.0000', $dec['breakdown']['advances_available']);
        $this->assertContains('advance_applied', array_column($dec['rows'], 'type'));

        // Release the next day: the live row disappears, yet the position of the day before stays reproducible.
        Carbon::setTestNow('2026-12-06 10:00:00');
        $actions->deallocate($this->owner, $advance->fresh(), $order, 'Erreur d’imputation');
        $this->assertSame(1, DB::table('payment_allocation_releases')->where('payment_id', $advance->id)->count());
        $this->assertSame('800.0000', $this->position('2026-12-06')['unbilled']);
        $this->assertSame('300.0000', $this->position('2026-12-05')['unbilled'], 'a past date is reproducible after the release');
        $after = $statements->build($this->org, $this->customer, $this->owner, '2026-12-01', '2026-12-31');
        $this->assertSame('300.0000', $after['closing'], 'customer-level balance is unchanged by moving money between advance and order');
        $this->assertSame('500.0000', $after['breakdown']['advances_available']);
    }

    public function test_settlement_idempotency_compares_every_material_attribute_and_survives_later_allocation_changes(): void
    {
        $order = $this->order('400.0000');
        $op = (string) Str::uuid();
        $first = $this->settle('300.0000', [[$order, '100.0000']], $op, ['reference' => 'VIR-1']);
        $this->assertNotNull($first->request_fingerprint);
        $this->assertSame($first->id, $this->settle('300.0000', [[$order, '100.0000']], $op, ['reference' => 'VIR-1'])->id, 'identical retry returns the original');

        // The original request is preserved even after the advance part is allocated later.
        app(ManageCustomerAdvanceAction::class)->allocate($this->owner, $first->fresh(), [['sales_order_id' => $order->id, 'amount' => '200.0000']]);
        $this->assertSame($first->id, $this->settle('300.0000', [[$order, '100.0000']], $op, ['reference' => 'VIR-1'])->id);
        $this->assertSame(1, Payment::query()->where('client_operation_id', $op)->count());

        $changes = [
            'amount' => ['301.0000', [[$order, '100.0000']], ['reference' => 'VIR-1']],
            'allocations' => ['300.0000', [[$order, '150.0000']], ['reference' => 'VIR-1']],
            'no allocation' => ['300.0000', [], ['reference' => 'VIR-1']],
            'reference' => ['300.0000', [[$order, '100.0000']], ['reference' => 'AUTRE']],
            'method' => ['300.0000', [[$order, '100.0000']], ['reference' => 'VIR-1', 'method' => 'cash']],
            'date' => ['300.0000', [[$order, '100.0000']], ['reference' => 'VIR-1', 'payment_date' => now()->subDay()->toDateString()]],
            'notes' => ['300.0000', [[$order, '100.0000']], ['reference' => 'VIR-1', 'notes' => 'x']],
        ];
        foreach ($changes as $label => [$amount, $allocations, $extra]) {
            try {
                $this->settle($amount, $allocations, $op, $extra);
                $this->fail("A reused operation id with a different {$label} must be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('client_operation_id', $e->errors(), $label);
            }
        }
        $other = $this->createFinancialAccount($this->org, ['type' => 'bank', 'code' => 'BANK2', 'accepted_methods' => ['bank_transfer']]);
        try {
            $this->settle('300.0000', [[$order, '100.0000']], $op, ['reference' => 'VIR-1', 'financial_account_id' => $other->id]);
            $this->fail('A different financial account must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('client_operation_id', $e->errors());
        }
        $this->assertSame(1, Payment::query()->where('client_operation_id', $op)->count());
    }

    public function test_an_opening_balance_can_be_settled_by_a_real_advance_and_reconciles_everywhere(): void
    {
        $opening = app(ManageCustomerOpeningBalanceAction::class)->record($this->owner, $this->customer, ['effective_date' => now()->toDateString(), 'amount' => '90.0000', 'currency_code' => 'MAD', 'reference' => 'REP-1', 'reason' => 'Dette historique']);
        $advance = $this->settle('60.0000');
        $actions = app(ManageCustomerAdvanceAction::class);
        $positions = fn () => app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$this->customer->id], $this->owner)[$this->customer->id];

        $this->assertSame('90.0000', $positions()['opening_balance']);
        $this->assertSame('60.0000', $positions()['advances_available']);
        $actions->settleOpeningBalance($this->owner, $advance->fresh(), $opening, '40.0000');
        $p = $positions();
        $this->assertSame('50.0000', $p['opening_balance'], 'outstanding historical debt');
        $this->assertSame('20.0000', $p['advances_available']);
        $this->assertSame('50.0000', $p['credit_exposure']);
        $this->assertSame('50.0000', app(CustomerCreditGuard::class)->exposureExcluding($this->org, $this->customer->id));
        $this->assertSame(0, DB::table('sales_orders')->where('organization_id', $this->org->id)->count(), 'no artificial order');
        $this->assertSame(0, DB::table('invoices')->where('organization_id', $this->org->id)->count(), 'no artificial invoice');
        $this->assertSame(1, DB::table('payments')->where('organization_id', $this->org->id)->count(), 'one real receipt');
        $statement = app(CustomerStatementService::class)->build($this->org, $this->customer, $this->owner, now()->subMonth()->toDateString(), now()->toDateString());
        $journal = app(FinanceJournalService::class)->events($this->org, FinancePeriod::current($this->org), null);
        $applied = $journal->firstWhere('type', 'opening_settlement');
        $this->assertNotNull($applied);
        $this->assertTrue($applied['informational'], 'applying an advance to an opening balance is never a second collection');
        $this->assertSame(1, $journal->where('type', 'payment')->count(), 'one real receipt in the journal');
        $this->assertSame('30.0000', $statement['closing'], '90 owed - 60 received (the application itself moves nothing at customer level)');
        $this->assertSame('20.0000', $statement['breakdown']['advances_available']);

        try {
            $actions->settleOpeningBalance($this->owner, $advance->fresh(), $opening, '30.0000');
            $this->fail('More than the available advance must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        try {
            app(ManageCustomerOpeningBalanceAction::class)->reverse($this->owner, $this->customer, $opening, 'x');
            $this->fail('An opening balance with an applied advance cannot be reversed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_balance', $e->errors());
        }
        try {
            app(ReversePaymentAction::class)->execute($this->owner, $advance->fresh(), 'x');
            $this->fail('A payment applied to an opening balance cannot be reversed while applied.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $settlementId = (int) DB::table('customer_opening_balance_settlements')->value('id');
        $actions->releaseOpeningSettlement($this->owner, $advance->fresh(), $settlementId, 'Erreur');
        $this->assertSame('90.0000', $positions()['opening_balance']);
        $this->assertSame('60.0000', $positions()['advances_available']);
    }

    public function test_credit_control_checks_increases_not_reductions_and_never_mixes_currencies(): void
    {
        $guard = app(CustomerCreditGuard::class);
        $terms = fn (array $extra = []) => app(SaveCustomerFinancialTermsAction::class)->execute($this->owner, $this->customer, array_replace(['credit_enabled' => true, 'credit_limit' => '1000.0000', 'policy' => 'block', 'financial_status' => 'normal'], $extra));
        $mode = function (string $enforcement, bool $deduct = false) {
            $this->org->settings = array_replace_recursive($this->org->settings ?? [], ['customer_credit' => ['enforcement' => $enforcement, 'deduct_advances' => $deduct]]);
            $this->org->save();
        };

        // Default stays off: nothing is ever blocked.
        $terms();
        $big = $this->order('1500.0000');
        $this->assertSame('confirmed', $big->status->value);

        // Commercial correction: a reduction passes even for a blocked customer, an increase is checked.
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '400.0000']);
        $extra = $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '300.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $mode('block');
        $terms(['financial_status' => 'blocked']);
        app(StartSalesOrderCorrectionAction::class)->execute($this->owner, $order, 'Réduction');
        app(RemoveSalesOrderLineAction::class)->execute($this->owner, $order->fresh(), $extra);
        $this->assertSame('confirmed', app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh())->status->value, 'a reducing correction is never blocked');

        app(StartSalesOrderCorrectionAction::class)->execute($this->owner, $order->fresh(), 'Augmentation');
        $this->addCustomLine($this->owner, $order->fresh(), ['unit_price_excl_tax' => '900.0000']);
        try {
            app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh());
            $this->fail('A correction that increases the commitment is checked.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order', $e->errors());
        }
        unset($extra);

        // Increase check used by addenda: only a positive increase on a confirmed order is evaluated.
        $terms(['financial_status' => 'normal', 'credit_limit' => '2000.0000']);
        $confirmed = $big->fresh();      // 1500 confirmed, already inside the exposure
        $guard->assertCanIncrease($this->owner, $confirmed, '0.0000', 'addendum');
        $guard->assertCanIncrease($this->owner, $confirmed, '100.0000', 'addendum');
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $guard->assertCanIncrease($this->owner, $confirmed, '1900.0000', 'addendum'));
    }

    public function test_credit_exposure_never_compares_incompatible_currencies_and_advances_are_optional(): void
    {
        $guard = app(CustomerCreditGuard::class);
        app(SaveCustomerFinancialTermsAction::class)->execute($this->owner, $this->customer, ['credit_enabled' => true, 'credit_limit' => '1000.0000', 'policy' => 'block', 'financial_status' => 'normal']);
        $this->order('900.0000');

        // A pending instrument and an unallocated advance do NOT reduce exposure by default.
        $this->settle('300.0000');
        $this->assertSame('900.0000', $guard->exposureInfo($this->org, $this->customer->id, 'MAD')['exposure']);
        $this->org->settings = ['customer_credit' => ['enforcement' => 'block', 'deduct_advances' => true]];
        $this->org->save();
        $this->assertSame('600.0000', $guard->exposureInfo($this->org->fresh(), $this->customer->id, 'MAD')['exposure'], 'verified advance deducted once when the policy is on');

        // A foreign-currency commitment makes the exposure non-comparable: refused under block, never silently summed.
        app(ManageCustomerOpeningBalanceAction::class)->record($this->owner, $this->customer, ['effective_date' => now()->toDateString(), 'amount' => '50.0000', 'currency_code' => 'EUR', 'reference' => 'E1', 'reason' => 'Dette en euros']);
        $info = $guard->exposureInfo($this->org->fresh(), $this->customer->id, 'MAD');
        $this->assertFalse($info['comparable']);
        $draft = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer);
        $this->addCustomLine($this->owner, $draft, ['unit_price_excl_tax' => '10.0000']);
        $this->expectException(ValidationException::class);
        app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft);
    }

    public function test_a_cheque_already_recorded_as_a_direct_payment_cannot_be_recorded_again_as_an_instrument(): void
    {
        $this->settle('100.0000', [], null, ['method' => 'cheque', 'reference' => 'CHQ-900']);
        $this->expectException(ValidationException::class);
        app(ManageCustomerInstrumentAction::class)->receive($this->owner, $this->customer, ['type' => 'cheque', 'instrument_number' => 'CHQ-900', 'issuing_bank' => 'BMCE',
            'amount' => '100.0000', 'received_date' => now()->toDateString(), 'financial_account_id' => $this->account->id], (string) Str::uuid());
    }

    public function test_instrument_receipt_is_idempotent_only_for_the_same_request(): void
    {
        $actions = app(ManageCustomerInstrumentAction::class);
        $data = ['type' => 'cheque', 'instrument_number' => 'CHQ-1', 'issuing_bank' => 'BMCE', 'amount' => '100.0000', 'received_date' => now()->toDateString(), 'financial_account_id' => $this->account->id];
        $key = (string) Str::uuid();
        $id = $actions->receive($this->owner, $this->customer, $data, $key);
        $this->assertSame($id, $actions->receive($this->owner, $this->customer, $data, $key));
        $this->expectException(ValidationException::class);
        $actions->receive($this->owner, $this->customer, ['instrument_number' => 'CHQ-2'] + $data, $key);
    }

    public function test_manual_reminders_render_french_and_arabic_without_sending_anything(): void
    {
        $order = $this->order('500.0000');
        DB::table('sales_orders')->where('id', $order->id)->update(['due_date' => now()->subDays(12)->toDateString()]);
        $templates = app(CustomerReminderTemplates::class);

        $fr = $this->getJson(route('finance.clients.reminders.preview', ['customer_id' => $this->customer->id, 'template' => 'overdue', 'language' => 'fr']))->assertOk()->json('message');
        $ar = $this->getJson(route('finance.clients.reminders.preview', ['customer_id' => $this->customer->id, 'template' => 'overdue', 'language' => 'ar']))->assertOk()->json('message');
        $this->assertStringContainsString('Atlas', $fr);
        $this->assertStringContainsString('500', $fr);
        $this->assertStringContainsString('12', $fr);
        $this->assertStringContainsString('مرحباً Atlas', $ar);
        $this->assertStringNotContainsString('{', $fr);

        $this->post(route('finance.clients.reminders.follow_up'), ['customer_id' => $this->customer->id, 'template' => 'overdue', 'language' => 'fr'])->assertRedirect();
        $this->assertDatabaseHas('follow_ups', ['organization_id' => $this->org->id, 'customer_id' => $this->customer->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_reminder.follow_up_created']);

        $this->post(route('finance.clients.reminders.template'), ['template' => 'courtesy', 'fr' => 'Bonjour {customer}, solde {amount} {currency}.', 'ar' => 'مرحباً {customer}، الرصيد {amount} {currency}.'])->assertRedirect();
        $this->assertTrue($templates->forOrganization($this->org->fresh())['courtesy']['customized']);
        $this->assertSame('Bonjour Atlas, solde 500.0000 MAD.', $templates->render($this->org->fresh(), 'courtesy', 'fr', ['customer' => 'Atlas', 'amount' => '500.0000', 'currency' => 'MAD']));

        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->org, $viewer, ['finance.view', 'customers.view'], 'active', 'V'.Str::random(4));
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->org, $this->store);
        $this->actingAs($viewer)->post(route('finance.clients.reminders.template'), ['template' => 'courtesy', 'fr' => 'x', 'ar' => 'y'])->assertForbidden();
    }

    public function test_aging_totals_and_row_count_do_not_depend_on_the_page(): void
    {
        foreach (['Atlas', 'Bravo', 'Cedre'] as $name) {
            $customer = $name === 'Atlas' ? $this->customer : $this->createCustomer($this->org, $name);
            $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer);
            $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '100.0000']);
            app(ConfirmSalesOrderAction::class)->execute($this->owner, $order);
        }
        $aging = app(CustomerAgingReport::class);
        $all = $aging->build($this->org, null, now());
        $page = $aging->build($this->org, null, now(), 2, 2);
        $this->assertSame(3, $all['total_rows']);
        $this->assertCount(3, $all['rows']);
        $this->assertCount(1, $page['rows']);
        $this->assertSame($all['totals'], $page['totals']);
        $this->assertSame('300.0000', $all['totals']['MAD']['total']);
    }

    public function test_statement_totals_cover_the_whole_period_independently_of_pagination(): void
    {
        $order = $this->order('1000.0000');
        foreach (range(1, 5) as $i) {
            $this->settle('10.0000', [[$order, '10.0000']]);
        }
        $statements = app(CustomerStatementService::class);
        $from = '2000-01-01';
        $to = now()->toDateString();
        $all = $statements->build($this->org, $this->customer, $this->owner, $from, $to);
        $page = $statements->build($this->org, $this->customer, $this->owner, $from, $to, 1, 2);
        $this->assertCount(6, $all['rows']);
        $this->assertCount(2, $page['rows']);
        $this->assertSame(3, $page['pages']);
        $this->assertSame(6, $page['total_rows']);
        foreach (['opening', 'closing', 'total_debit', 'total_credit'] as $key) {
            $this->assertSame($all[$key], $page[$key], $key.' must not depend on the displayed page');
        }
        $this->assertSame('950.0000', $all['closing']);
        $last = $statements->build($this->org, $this->customer, $this->owner, $from, $to, 3, 2);
        $this->assertSame($all['rows'][5]['balance'], $last['rows'][1]['balance']);
        $this->assertSame($all['closing'], $all['breakdown']['unbilled'], 'commercial balance = unbilled exposure when nothing is invoiced');
    }
}
