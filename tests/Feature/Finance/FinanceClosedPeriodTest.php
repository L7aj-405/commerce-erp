<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\GenerateRecurringFinanceObligationsAction;
use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\ManageFinancePaymentInstrumentAction;
use App\Actions\Finance\ManageFinanceSupplierInstallmentPlanAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Models\FinanceObligation;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentTestCase;

/**
 * Sprint 0 / WS2 — a closed month refuses every financial posting or change
 * dated inside it; reopening (with a reason) is the only correction path.
 */
class FinanceClosedPeriodTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withFreshAuthentication(level: 1);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(10, 0));
    }

    public function test_customer_payment_dated_in_a_closed_month_is_refused_and_accepted_after_reopening(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture();
        $this->close($owner, $organization, '2026-09');

        $this->assertRefused(fn () => $this->recordPayment($owner, $order, $account, '10.0000', ['payment_date' => '2026-09-20']), 'payment_date');
        $this->assertDatabaseCount('payments', 0);

        // An open month (October) and today are untouched by the September close.
        $this->recordPayment($owner, $order, $account, '10.0000', ['payment_date' => '2026-10-02']);
        $this->recordPayment($owner, $order, $account, '10.0000');

        app(ManageFinanceMonthReviewAction::class)->reopen($owner, $organization, FinancePeriod::fromMonth('2026-09'), 'Encaissement oublié');
        $late = $this->recordPayment($owner, $order, $account, '10.0000', ['payment_date' => '2026-09-20']);
        $this->assertSame('2026-09-20', $late->payment_date->toDateString());
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'finance_month.reopened']);
    }

    public function test_reversing_a_payment_of_a_closed_month_is_refused(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000', ['payment_date' => '2026-09-20']);
        $this->close($owner, $organization, '2026-09');

        $this->assertRefused(fn () => app(ReversePaymentAction::class)->execute($owner, $payment, 'Erreur'), 'payment');
        $this->assertSame('posted', $payment->fresh()->status->value);
    }

    public function test_refund_is_dated_today_and_refused_while_the_current_month_is_marked_closed(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '100.0000');
        // The current month cannot be closed through the UI (it has not ended):
        // simulate the closed state to prove the refund path is guarded too.
        $this->markClosed($organization, '2026-11');

        $this->assertRefused(fn () => app(RefundPaymentAction::class)->execute($owner, $payment, $order, 'Annulation'), 'refund_date');
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_outgoing_payments_obligations_and_their_reversal_respect_the_closed_month(): void
    {
        [$owner, $organization, $account] = $this->financeFixture();
        $september = $this->obligation($owner, $organization, ['due_date' => '2026-09-15']);
        $payment = $this->outgoing($owner, $organization, $account->id, $september->id, '1000.0000', '2026-09-20');
        $this->close($owner, $organization, '2026-09');

        $this->assertRefused(fn () => $this->outgoing($owner, $organization, $account->id, $september->id, '1000.0000', '2026-09-25'), 'payment_date');
        $this->assertRefused(fn () => app(ReverseFinanceOutgoingPaymentAction::class)->execute($owner, $payment, 'Erreur'), 'payment');
        $this->assertRefused(fn () => $this->obligation($owner, $organization, ['due_date' => '2026-09-30']), 'due_date');
        // Moving an obligation OUT of a closed month changes the closed figures too.
        $this->assertRefused(fn () => app(ManageFinanceObligationAction::class)->update($owner, $september, $this->obligationData(['due_date' => '2026-10-15'])), 'due_date');

        $october = $this->obligation($owner, $organization, ['due_date' => '2026-10-15']);
        // …and moving one INTO it is refused as well.
        $this->assertRefused(fn () => app(ManageFinanceObligationAction::class)->update($owner, $october, $this->obligationData(['due_date' => '2026-09-28'])), 'due_date');
        $unpaidSeptember = FinanceObligation::query()->whereKey($october->id)->firstOrFail();
        DB::table('finance_obligations')->where('id', $unpaidSeptember->id)->update(['due_date' => '2026-09-10']);
        $this->assertRefused(fn () => app(ManageFinanceObligationAction::class)->cancel($owner, $unpaidSeptember->fresh(), 'Doublon'), 'obligation');

        // Open month: paying October is unaffected.
        $open = $this->obligation($owner, $organization, ['due_date' => '2026-10-20']);
        $this->outgoing($owner, $organization, $account->id, $open->id, '500.0000', '2026-10-21');
        $this->assertSame('posted', $payment->fresh()->status->value);
    }

    public function test_installment_plans_and_instruments_respect_the_closed_month(): void
    {
        [$owner, $organization, $account, $supplier] = $this->financeFixture();
        $this->close($owner, $organization, '2026-09');

        $this->assertRefused(fn () => app(ManageFinanceSupplierInstallmentPlanAction::class)->create($owner, $organization, [
            'supplier_id' => $supplier->id, 'agreed_total' => '2000.0000', 'currency_code' => 'MAD',
            'installments' => [['due_date' => '2026-09-30', 'amount' => '1000.0000'], ['due_date' => '2026-10-30', 'amount' => '1000.0000']],
        ]), 'installments');
        $this->assertDatabaseCount('finance_supplier_installment_plans', 0);

        $obligation = $this->obligation($owner, $organization, ['due_date' => '2026-10-15']);
        $instruments = app(ManageFinancePaymentInstrumentAction::class);
        $this->assertRefused(fn () => $instruments->create($owner, $obligation, [
            'type' => 'cheque', 'external_reference' => 'CHQ-1', 'amount' => '500.0000',
            'issue_date' => '2026-09-01', 'maturity_date' => '2026-09-30', 'financial_account_id' => $account->id,
        ]), 'maturity_date');

        $cheque = $instruments->create($owner, $obligation, [
            'type' => 'cheque', 'external_reference' => 'CHQ-2', 'amount' => '500.0000',
            'issue_date' => '2026-09-25', 'maturity_date' => '2026-10-10', 'financial_account_id' => $account->id,
        ]);
        // Clearing books the outgoing payment on the bank value date: refused inside the closed month.
        $this->assertRefused(fn () => $instruments->clear($owner, $cheque, '2026-09-28'), 'payment_date');
        $cleared = $instruments->clear($owner, $cheque, '2026-10-12');
        $this->assertSame('2026-10-12', $cleared->outgoingPayment->payment_date->toDateString());
    }

    public function test_recurring_generation_skips_a_closed_month_without_failing(): void
    {
        [$owner, $organization] = $this->financeFixture();
        $template = new FinanceRecurringObligationTemplate;
        $template->organization_id = $organization->id;
        $template->template_number = 'REC-TEST-1';
        $template->category = 'rent';
        $template->label = 'Loyer';
        $template->beneficiary_name = 'Bailleur';
        $template->planned_amount = '5000.0000';
        $template->currency_code = 'MAD';
        $template->due_day_mode = 'fixed';
        $template->due_day = 5;
        $template->start_date = '2026-01-01';
        $template->active = true;
        $template->save();
        $this->close($owner, $organization, '2026-09');

        $result = app(GenerateRecurringFinanceObligationsAction::class)->execute($organization, '2026-09');
        $this->assertSame(['created' => 0, 'existing' => 0, 'skipped' => 1], $result);
        $this->assertSame(1, app(GenerateRecurringFinanceObligationsAction::class)->execute($organization, '2026-10')['created']);
    }

    public function test_closing_never_affects_another_organization(): void
    {
        [$owner, $organization] = $this->financeFixture();
        $this->close($owner, $organization, '2026-09');
        [$otherOwner, $other] = $this->financeFixture();

        $this->obligation($otherOwner, $other, ['due_date' => '2026-09-20']);
        $this->assertDatabaseHas('finance_obligations', ['organization_id' => $other->id]);
    }

    private function assertRefused(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Expected the closed period to refuse the operation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertStringContainsString('est clôturée', $exception->errors()[$field][0]);
        }
    }

    public function test_reopening_needs_the_owner_or_the_reopen_permission_and_a_reason(): void
    {
        [$owner, $organization] = $this->paymentFixture();
        $manager = User::factory()->create();
        $this->addOrganizationMember($organization, $manager, ['finance.monthly_situation.manage']);
        $this->activate($manager, $organization);
        $this->markClosed($organization, '2026-09');
        $action = app(ManageFinanceMonthReviewAction::class);

        // Closing and reopening are separate duties: managing the situation is not enough.
        try {
            $action->reopen($manager, $organization->fresh(), FinancePeriod::fromMonth('2026-09'), 'Correction');
            $this->fail('A manager without the reopen permission must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('closed', DB::table('finance_month_reviews')->where('organization_id', $organization->id)->value('status'));

        $reopener = User::factory()->create();
        $this->addOrganizationMember($organization, $reopener, ['finance.monthly_situation.manage', 'finance.periods.reopen']);
        $this->activate($reopener, $organization);
        try {
            $action->reopen($reopener, $organization->fresh(), FinancePeriod::fromMonth('2026-09'), '   ');
            $this->fail('A reason is mandatory.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $action->reopen($reopener, $organization->fresh(), FinancePeriod::fromMonth('2026-09'), 'Encaissement oublié');
        $this->assertSame('open', DB::table('finance_month_reviews')->where('organization_id', $organization->id)->value('status'));
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'finance_month.reopened', 'actor_id' => $reopener->id]);
    }

    private function close(User $owner, Organization $organization, string $month): void
    {
        $this->assertSame($organization->id, $owner->fresh()->active_organization_id);
        app(ManageFinanceMonthReviewAction::class)->close($owner, $organization->fresh(), FinancePeriod::fromMonth($month), 'Clôture', true);
    }

    private function markClosed(Organization $organization, string $month): void
    {
        DB::table('finance_month_reviews')->insert([
            'organization_id' => $organization->id, 'period' => $month.'-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{User, Organization, FinancialAccount, Supplier} */
    private function financeFixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK-'.Str::upper(Str::random(5)), 'accepted_methods' => ['bank_transfer', 'cheque']]);
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = 'Fournisseur '.Str::random(4);
        $supplier->active = true;
        $supplier->save();

        return [$owner, $organization, $account, $supplier];
    }

    private function obligation(User $owner, Organization $organization, array $overrides = []): FinanceObligation
    {
        return app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->obligationData($overrides));
    }

    private function obligationData(array $overrides = []): array
    {
        return array_replace(['store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur',
            'reference' => null, 'due_date' => '2026-10-15', 'planned_amount' => '10000.0000', 'currency_code' => 'MAD',
            'planned_payment_method' => 'bank_transfer', 'preferred_financial_account_id' => null, 'notes' => null], $overrides);
    }

    private function outgoing(User $owner, Organization $organization, int $accountId, int $obligationId, string $amount, string $date)
    {
        return app(RecordFinanceOutgoingPaymentAction::class)->execute($owner, $organization, [
            'store_id' => null, 'financial_account_id' => $accountId, 'supplier_id' => null, 'beneficiary_name' => 'Bénéficiaire',
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => $date,
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => $obligationId, 'amount' => $amount]],
        ]);
    }
}
