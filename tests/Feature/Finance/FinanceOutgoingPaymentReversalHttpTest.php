<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Models\FinanceObligation;
use App\Models\FinanceOutgoingPayment;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Finance\FinanceObligationCalculator;
use App\Services\Finance\FinancialAccountBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

/** M-62: the real supplier-payment reversal endpoint (reason, once-only, permissions, fresh auth, atomic refusal). */
class FinanceOutgoingPaymentReversalHttpTest extends PaymentTestCase
{
    private User $owner;

    private $org;

    private FinancialAccount $account;

    private FinanceObligation $obligation;

    private FinanceOutgoingPayment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->activate($this->owner, $this->org, $this->createStore($this->org, $this->owner));
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'code' => 'BANK', 'accepted_methods' => ['bank_transfer']]);
        DB::table('financial_account_opening_positions')->insert([
            'organization_id' => $this->org->id, 'financial_account_id' => $this->account->id, 'effective_date' => '2026-01-01',
            'amount' => '1000.0000', 'currency_code' => 'MAD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->obligation = app(ManageFinanceObligationAction::class)->create($this->owner, $this->org, [
            'store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur', 'reference' => null,
            'due_date' => '2026-10-25', 'planned_amount' => '120.0000', 'currency_code' => 'MAD', 'planned_payment_method' => null,
            'preferred_financial_account_id' => null, 'notes' => null,
        ]);
        $this->actingAs($this->owner)->postJson(route('security.confirm.password'), ['password' => 'password'])->assertOk();
        $this->postJson(route('finance.monthly-situation.payments.store'), [
            'store_id' => null, 'financial_account_id' => $this->account->id, 'supplier_id' => null, 'beneficiary_name' => 'Bailleur',
            'payment_method' => 'bank_transfer', 'amount' => '50.0000', 'currency_code' => 'MAD', 'payment_date' => '2026-10-20',
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => $this->obligation->id, 'amount' => '50.0000']],
        ])->assertStatus(302);
        $this->payment = FinanceOutgoingPayment::query()->firstOrFail();
    }

    private function reverse(array $body, ?User $as = null)
    {
        return ($as ? $this->actingAs($as) : $this)->postJson(route('finance.monthly-situation.payments.reverse', $this->payment), $body);
    }

    private function remaining(): string
    {
        return app(FinanceObligationCalculator::class)->values($this->obligation->fresh(), Carbon::parse('2026-11-01'))['remaining'];
    }

    protected function acctBalance(): string
    {
        $row = collect(app(FinancialAccountBalanceService::class)->organizationPosition($this->org, Carbon::parse('2026-11-23'))['accounts'] ?? [])->firstWhere('id', $this->account->id);

        return (string) ($row['balance'] ?? 'n/a');
    }

    private function snapshot(): array
    {
        return [
            'payments' => DB::table('finance_outgoing_payments')->get()->toArray(),
            'allocs' => DB::table('finance_outgoing_payment_allocations')->get()->toArray(),
            'audit' => DB::table('audit_logs')->where('event', 'like', 'finance_outgoing_payment.%')->count(),
            'remaining' => $this->remaining(),
            'balance' => $this->acctBalance(),
        ];
    }

    public function test_baseline(): void
    {
        $this->assertSame('70.0000', $this->remaining());
    }

    public function test_invalid_reasons_rejected_without_mutation(): void
    {
        $before = $this->snapshot();
        foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => "\n\t "], ['reason' => null], ['reason' => ['x']], ['reason' => str_repeat('a', 2001)]] as $body) {
            $r = $this->reverse($body);
            $r->assertStatus(422)->assertJsonValidationErrors('reason');
        }
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame('posted', $this->payment->fresh()->status->value);
    }

    public function test_valid_reversal_once_restores_balance_and_keeps_audit_then_repeat_rejected(): void
    {
        $before = $this->snapshot();

        $this->reverse(['reason' => '  Paiement rejeté par la banque  '])->assertStatus(302);
        $p = $this->payment->fresh();
        $this->assertSame('reversed', $p->status->value);
        $this->assertSame('Paiement rejeté par la banque', $p->reversal_reason);
        $this->assertSame($this->owner->id, $p->reversed_by_user_id);
        $reversedAt = $p->reversed_at;

        $after = $this->snapshot();
        $this->assertSame('120.0000', $after['remaining']);
        $this->assertCount(1, $after['allocs'], 'allocation row retained');
        $this->assertSame(1, DB::table('finance_outgoing_payments')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'finance_outgoing_payment.reversed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'finance_outgoing_payment.posted')->count());

        // Repeat
        $again = $this->reverse(['reason' => 'Encore']);
        $again->assertStatus(422);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'finance_outgoing_payment.reversed')->count());
        $this->assertEquals($reversedAt, $this->payment->fresh()->reversed_at);
        $this->assertSame('120.0000', $this->remaining());
        $this->assertSame($after['balance'], $this->acctBalance());
    }

    public function test_user_without_permission_cannot_reverse(): void
    {
        $member = User::factory()->create();
        $this->addOrganizationMember($this->org, $member, ['finance.view'], roleName: 'NoPay '.Str::random(5));
        $this->activate($member, $this->org, $this->createStore($this->org, $this->owner));
        $this->actingAs($member)->postJson(route('security.confirm.password'), ['password' => 'password'])->assertOk();
        $before = $this->snapshot();
        $r = $this->reverse(['reason' => 'Test'], $member);
        $r->assertForbidden();
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_other_tenant_user_cannot_reverse(): void
    {
        $other = User::factory()->create();
        $org2 = $this->createOrganization($other);
        $this->activate($other, $org2, $this->createStore($org2, $other));
        $this->actingAs($other)->postJson(route('security.confirm.password'), ['password' => 'password'])->assertOk();
        $before = $this->snapshot();
        $r = $this->reverse(['reason' => 'Test'], $other);
        $r->assertNotFound();
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_fresh_auth_required(): void
    {
        $this->app['session.store']->flush();
        $this->flushSession();
        $fresh = User::factory()->create();
        $this->addOrganizationMember($this->org, $fresh, ['finance.outgoing_payments.manage'], roleName: 'Mgr '.Str::random(5));
        $this->activate($fresh, $this->org, $this->createStore($this->org, $this->owner));
        $before = $this->snapshot();
        $r = $this->actingAs($fresh)->postJson(route('finance.monthly-situation.payments.reverse', $this->payment), ['reason' => 'Test']);
        $r->assertStatus(409);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame('posted', $this->payment->fresh()->status->value);
    }

    public function test_closed_period_failure_leaves_everything_unchanged(): void
    {
        DB::table('finance_month_reviews')->insert([
            'organization_id' => $this->org->id, 'period' => '2026-10-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->snapshot();
        $r = $this->reverse(['reason' => 'Test']);
        $r->assertStatus(422);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame('posted', $this->payment->fresh()->status->value);
    }
}
