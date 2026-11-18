<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Actions\Payments\RefundPaymentAction;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\FinanceMonthReviewService;
use App\Services\Finance\FinancePeriod;
use App\Support\BusinessTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

/**
 * Sprint 0 / WS3 — "today" for payments, refunds, cheque/traite clearing and
 * month review is the organization's business date (Africa/Casablanca), never
 * the server's UTC date.
 *
 * Instants are chosen in periods where Morocco's offset DIFFERS from UTC, so the UTC date and the
 * business date can differ: UTC+1 from 22 March to 19 September 2026 (the fixtures use June and
 * May 2026) and UTC+0 during Ramadan 2026 (15 Feb 02:00 UTC → 22 Mar 02:00 UTC). Since 20 September
 * 2026 Morocco is on UTC+0 all year (Decree 2.26.530): UTC and Casablanca then share the same date, so
 * November 2026 or later can no longer reveal a UTC-vs-local defect — never use them here
 * (see tests/Unit/MoroccoLegalTimeTest.php for the legal-time facts).
 */
class BusinessDateTimezoneTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication(level: 1);
    }

    public function test_business_today_follows_casablanca_including_the_ramadan_offset_change(): void
    {
        $organization = $this->casablancaOrganization();

        // UTC+1 (June 2026): 23:30 UTC is already tomorrow in Casablanca.
        $this->travelTo(Carbon::parse('2026-06-04 23:30:00', 'UTC'));
        $this->assertSame('2026-06-05', BusinessTime::today($organization));
        $this->assertSame('2026-06', FinancePeriod::current($organization)->month);

        // Ramadan, UTC+0: 23:30 UTC is still the same day.
        $this->travelTo(Carbon::parse('2026-03-01 23:30:00', 'UTC'));
        $this->assertSame('2026-03-01', BusinessTime::today($organization));

        // Month boundary at local midnight: 31 May 23:30 UTC = 1 June 00:30 local (UTC+1).
        $this->travelTo(Carbon::parse('2026-05-31 23:30:00', 'UTC'));
        $this->assertSame('2026-06', FinancePeriod::current($organization)->month);
        $this->assertSame('2026-05', FinancePeriod::current()->month, 'Without an organization the UTC month is unchanged.');
    }

    public function test_same_day_payment_just_after_local_midnight_is_not_a_backdate(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->casablancaPaymentFixture();
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);

        $this->travelTo(Carbon::parse('2026-06-04 23:30:00', 'UTC')); // 00:30 on 5 June in Casablanca (UTC+1)
        $this->actingAs($employee)->post(route('sales.orders.payments.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'payments' => [['method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '10.0000', 'payment_date' => '2026-06-05']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('payments', ['organization_id' => $organization->id, 'received_by_user_id' => $employee->id]);
        $this->assertSame('2026-06-05', Payment::query()->where('organization_id', $organization->id)->firstOrFail()->payment_date->toDateString());

        // The UTC date (4 June) is yesterday for this organization: a real backdate.
        $this->actingAs($employee)->post(route('sales.orders.payments.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'payments' => [['method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '10.0000', 'payment_date' => '2026-06-04']],
        ])->assertForbidden();
    }

    public function test_same_day_payment_during_ramadan_uses_the_utc_plus_zero_offset(): void
    {
        [, $organization, $store, $order, $account] = $this->casablancaPaymentFixture();
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);

        $this->travelTo(Carbon::parse('2026-03-01 23:30:00', 'UTC')); // still 1 Mar in Casablanca (UTC+0)
        $this->actingAs($employee)->post(route('sales.orders.payments.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'payments' => [['method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '10.0000', 'payment_date' => '2026-03-02']],
        ])->assertForbidden();
        $this->actingAs($employee)->post(route('sales.orders.payments.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'payments' => [['method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '10.0000', 'payment_date' => '2026-03-01']],
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_refund_is_dated_with_the_local_business_date(): void
    {
        [$owner, , , $order, $account] = $this->casablancaPaymentFixture();
        $this->travelTo(Carbon::parse('2026-06-04 23:30:00', 'UTC'));
        $payment = $this->recordPayment($owner, $order, $account, '100.0000', ['payment_date' => '2026-06-05']);

        $refund = app(RefundPaymentAction::class)->execute($owner, $payment, $order, 'Annulation client');
        $this->assertSame('2026-06-05', $refund->refund_date->toDateString());
    }

    public function test_instrument_clearing_uses_the_local_date_and_refuses_future_value_dates(): void
    {
        [$owner, $organization] = $this->casablancaFinanceOwner();
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BNK', 'accepted_methods' => ['bank_transfer', 'cheque']]);
        $obligation = app(ManageFinanceObligationAction::class)->create($owner, $organization, [
            'store_id' => null, 'category' => 'supplier', 'label' => 'Facture fournisseur', 'supplier_id' => null, 'beneficiary_name' => 'Fournisseur',
            'reference' => null, 'due_date' => '2026-06-30', 'planned_amount' => '1000.0000', 'currency_code' => 'MAD',
            'planned_payment_method' => 'cheque', 'preferred_financial_account_id' => null, 'notes' => null,
        ]);
        $instruments = app(ManageFinancePaymentInstrumentAction::class);
        $make = fn (string $reference) => $instruments->create($owner, $obligation, [
            'type' => 'cheque', 'external_reference' => $reference, 'amount' => '100.0000',
            'issue_date' => '2026-06-01', 'maturity_date' => '2026-06-04', 'financial_account_id' => $account->id,
        ]);

        $this->travelTo(Carbon::parse('2026-06-04 23:30:00', 'UTC')); // 5 June locally (UTC+1)
        $default = $instruments->clear($owner, $make('CHQ-A'));
        $this->assertSame('2026-06-05', $default->outgoingPayment->payment_date->toDateString());

        $explicit = $instruments->clear($owner, $make('CHQ-B'), '2026-06-03');
        $this->assertSame('2026-06-03', $explicit->outgoingPayment->payment_date->toDateString());

        try {
            $instruments->clear($owner, $make('CHQ-C'), '2026-06-06');
            $this->fail('A future value date must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cleared_on', $exception->errors());
        }
        try {
            $instruments->clear($owner, $make('CHQ-D'), '2026-05-30');
            $this->fail('A value date before the issue date must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cleared_on', $exception->errors());
        }
    }

    public function test_month_close_waits_for_the_local_end_of_month(): void
    {
        [$owner, $organization] = $this->casablancaFinanceOwner();
        $may = FinancePeriod::fromMonth('2026-05');

        // 31 May 22:30 UTC = 23:30 local (UTC+1): May has not ended in Casablanca.
        $this->travelTo(Carbon::parse('2026-05-31 22:30:00', 'UTC'));
        $this->assertFalse(app(FinanceMonthReviewService::class)->state($organization, $may)['can_close']);
        try {
            app(ManageFinanceMonthReviewAction::class)->close($owner, $organization, $may, null, true);
            $this->fail('May cannot be closed before its local end.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('month', $exception->errors());
        }

        // 31 May 23:30 UTC = 1 June 00:30 local: May is over, June is reviewable.
        $this->travelTo(Carbon::parse('2026-05-31 23:30:00', 'UTC'));
        $this->assertTrue(app(FinanceMonthReviewService::class)->state($organization, $may)['can_close']);
        $this->assertTrue(app(FinanceMonthReviewService::class)->state($organization, FinancePeriod::fromMonth('2026-06'))['can_review']);
        $this->assertSame('closed', app(ManageFinanceMonthReviewAction::class)->close($owner, $organization, $may, null, true)->status);
    }

    private function casablancaOrganization(): Organization
    {
        $owner = User::factory()->create();

        return $this->withCasablanca($this->createOrganization($owner));
    }

    private function withCasablanca(Organization $organization): Organization
    {
        $organization->settings = [...($organization->settings ?? []), 'timezone' => 'Africa/Casablanca'];
        $organization->save();

        return $organization->fresh();
    }

    /** @return array{User, Organization} */
    private function casablancaFinanceOwner(): array
    {
        $owner = User::factory()->create();
        $organization = $this->withCasablanca($this->createOrganization($owner));
        $this->activate($owner, $organization, $this->createStore($organization, $owner));

        return [$owner, $organization->fresh()];
    }

    private function casablancaPaymentFixture(): array
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $this->withCasablanca($organization);

        return [$owner, $organization->fresh(), $store, $order->fresh(), $account];
    }
}
