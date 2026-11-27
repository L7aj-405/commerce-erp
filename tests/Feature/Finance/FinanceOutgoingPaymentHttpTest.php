<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Models\FinanceObligation;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

/** M-51: the real "Comptabiliser" endpoint never posts more than the obligation's remaining balance. */
class FinanceOutgoingPaymentHttpTest extends PaymentTestCase
{
    private User $owner;

    private FinancialAccount $account;

    private FinanceObligation $obligation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $organization = $this->createOrganization($this->owner);
        $this->activate($this->owner, $organization, $this->createStore($organization, $this->owner));
        $this->account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK', 'accepted_methods' => ['bank_transfer']]);
        $this->obligation = app(ManageFinanceObligationAction::class)->create($this->owner, $organization, [
            'store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur', 'reference' => null,
            'due_date' => '2026-10-25', 'planned_amount' => '120.0000', 'currency_code' => 'MAD', 'planned_payment_method' => null,
            'preferred_financial_account_id' => null, 'notes' => null,
        ]);
        $this->actingAs($this->owner)->postJson(route('security.confirm.password'), ['password' => 'password'])->assertOk();
    }

    public function test_overpayment_is_rejected_with_422_and_posts_nothing(): void
    {
        $this->pay('121.0000')->assertStatus(422)->assertJsonValidationErrors('allocations');
        $this->assertNothingPosted();

        // Amount and allocation must also agree exactly.
        $this->pay('121.0000', '120.0000')->assertStatus(422)->assertJsonValidationErrors('allocations');
        $this->assertNothingPosted();
    }

    public function test_exact_payment_settles_the_obligation_and_any_further_payment_is_rejected(): void
    {
        $this->pay('120.0000')->assertRedirect();
        $this->assertPosted(1, '120.0000');
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'finance_outgoing_payment.posted')->count());

        $this->pay('0.0001')->assertStatus(422)->assertJsonValidationErrors('allocations');
        $this->assertPosted(1, '120.0000');
    }

    public function test_partial_payment_leaves_the_balance_and_a_second_overpayment_is_rejected(): void
    {
        $this->pay('50.0000')->assertRedirect();
        $this->assertPosted(1, '50.0000');

        $this->pay('70.0001')->assertStatus(422)->assertJsonValidationErrors('allocations');
        $this->pay('121.0000')->assertStatus(422)->assertJsonValidationErrors('allocations');
        $this->assertPosted(1, '50.0000');

        $this->pay('70.0000')->assertRedirect();
        $this->assertPosted(2, '120.0000');
    }

    public function test_invalid_amounts_are_rejected_before_anything_is_posted(): void
    {
        foreach (['0', '0.0000', '-1', '-120.0000', 'abc', '120.00001'] as $amount) {
            $this->pay($amount)->assertStatus(422)->assertJsonValidationErrors('amount');
        }
        $this->assertNothingPosted();
    }

    public function test_replaying_the_same_idempotency_key_does_not_post_twice(): void
    {
        $key = (string) Str::uuid();
        $this->pay('50.0000', null, $key)->assertRedirect();
        $this->pay('50.0000', null, $key)->assertRedirect();
        $this->assertPosted(1, '50.0000');

        $this->pay('60.0000', null, $key)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->assertPosted(1, '50.0000');
    }

    private function pay(string $amount, ?string $allocation = null, ?string $key = null)
    {
        return $this->postJson(route('finance.monthly-situation.payments.store'), [
            'store_id' => null, 'financial_account_id' => $this->account->id, 'supplier_id' => null, 'beneficiary_name' => 'Bailleur',
            'payment_method' => 'bank_transfer', 'amount' => $amount, 'currency_code' => 'MAD', 'payment_date' => '2026-10-20',
            'reference' => null, 'notes' => null, 'idempotency_key' => $key ?? (string) Str::uuid(),
            'allocations' => [['obligation_id' => $this->obligation->id, 'amount' => $allocation ?? $amount]],
        ]);
    }

    private function assertNothingPosted(): void
    {
        $this->assertDatabaseCount('finance_outgoing_payments', 0);
        $this->assertDatabaseCount('finance_outgoing_payment_allocations', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', 'finance_outgoing_payment.%')->count());
    }

    private function assertPosted(int $payments, string $total): void
    {
        $this->assertDatabaseCount('finance_outgoing_payments', $payments);
        $this->assertSame($payments, DB::table('finance_outgoing_payment_allocations')->count());
        $this->assertSame($total, number_format((float) DB::table('finance_outgoing_payment_allocations')->where('obligation_id', $this->obligation->id)->sum('amount'), 4, '.', ''));
    }
}
