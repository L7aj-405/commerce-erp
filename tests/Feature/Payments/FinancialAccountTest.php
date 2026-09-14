<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\ManageFinancialAccountAction;
use App\Enums\PaymentMethod;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

class FinancialAccountTest extends PaymentTestCase
{
    public function test_owner_can_create_and_update_an_organization_account_with_audit(): void
    {
        [$owner, $organization] = $this->paymentFixture();
        $action = app(ManageFinancialAccountAction::class);
        $account = $action->create($owner, $organization, ['name' => 'Bank', 'code' => 'bank-1', 'type' => 'bank', 'status' => 'active', 'currency_code' => 'mad']);
        $updated = $action->update($owner, $account, ['name' => 'Primary Bank', 'code' => 'BANK-1', 'type' => 'bank', 'status' => 'inactive', 'currency_code' => 'MAD']);
        $this->assertSame('Primary Bank', $updated->name);
        $this->assertSame('inactive', $updated->status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'financial_account.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'financial_account.updated']);
    }

    public function test_sales_employee_cannot_manage_financial_accounts(): void
    {
        [, $organization, $store] = $this->paymentFixture();
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);
        $this->actingAs($employee)->post(route('financial-accounts.store'), $this->accountPayload())->assertForbidden();
    }

    public function test_inactive_account_cannot_receive_payment(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $account->status = 'inactive';
        $account->save();
        $this->expectException(ValidationException::class);
        $this->recordPayment($owner, $order, $account, '10.0000');
    }

    public function test_method_must_match_account_type(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture();
        $bank = $this->createFinancialAccount($organization, ['name' => 'Bank', 'code' => 'BANK', 'type' => 'bank']);
        $this->expectException(ValidationException::class);
        $this->recordPayment($owner, $order, $bank, '10.0000', ['method' => 'cash']);
    }

    public function test_account_currency_must_match_order_currency(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture();
        $euro = $this->createFinancialAccount($organization, ['name' => 'Euro Cash', 'code' => 'EUR-CASH', 'currency_code' => 'EUR']);
        $this->expectException(ValidationException::class);
        $this->recordPayment($owner, $order, $euro, '10.0000');
    }

    public function test_sales_employee_cannot_backdate_but_owner_can(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $employee);
        $date = now()->subDay()->toDateString();
        $this->actingAs($employee)->post(route('sales.orders.payments.store', $order), [
            'client_operation_id' => (string) Str::uuid(), 'payments' => [$this->paymentPayload($account->id, '10.0000', $date)],
        ])->assertForbidden();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000', ['payment_date' => $date]);
        $this->assertSame($date, $payment->payment_date->toDateString());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'received_by_user_id' => $owner->id]);
    }

    /**
     * A single Finance Account must be able to accept several Payment
     * methods at once — a company with one bank account receiving
     * transfers, TPE settlements AND cheques is a legitimate real case, and
     * must never be forced into creating one account per method.
     */
    public function test_a_single_account_can_accept_multiple_payment_methods(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture();
        $account = $this->createFinancialAccount($organization, [
            'name' => 'Banque CIH', 'code' => 'CIH', 'type' => 'bank',
            'accepted_methods' => ['bank_transfer', 'card', 'cheque'],
        ]);

        $this->recordPayment($owner, $order, $account, '4.0000', ['method' => 'bank_transfer', 'client_operation_id' => (string) Str::uuid()]);
        $this->recordPayment($owner, $order, $account, '3.0000', ['method' => 'card', 'client_operation_id' => (string) Str::uuid()]);
        $this->recordPayment($owner, $order, $account, '3.0000', ['method' => 'cheque', 'client_operation_id' => (string) Str::uuid()]);

        $this->assertDatabaseCount('payments', 3);
        $this->assertSame(['bank_transfer', 'card', 'cheque'], $account->fresh()->accepted_methods);
    }

    public function test_a_single_account_can_accept_every_supported_payment_method(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture('40.0000');
        $account = $this->createFinancialAccount($organization, [
            'name' => 'Compte principal', 'code' => 'PRINCIPAL', 'type' => 'other',
            'accepted_methods' => ['cash', 'card', 'bank_transfer', 'cheque'],
        ]);

        foreach (['cash', 'card', 'bank_transfer', 'cheque'] as $method) {
            $this->recordPayment($owner, $order, $account, '10.0000', ['method' => $method, 'client_operation_id' => (string) Str::uuid()]);
        }

        $this->assertDatabaseCount('payments', 4);
    }

    public function test_an_account_with_a_single_accepted_method_rejects_any_other(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture();
        $cashOnly = $this->createFinancialAccount($organization, ['name' => 'Caisse showroom', 'code' => 'CAISSE', 'type' => 'cash', 'accepted_methods' => ['cash']]);

        $this->recordPayment($owner, $order, $cashOnly, '5.0000', ['method' => 'cash']);
        $this->assertDatabaseCount('payments', 1);

        $this->expectException(ValidationException::class);
        $this->recordPayment($owner, $order, $cashOnly, '5.0000', ['method' => 'card', 'client_operation_id' => (string) Str::uuid()]);
    }

    public function test_incompatible_method_is_rejected_even_for_a_multi_method_account(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture();
        $account = $this->createFinancialAccount($organization, [
            'name' => 'Banque CIH', 'code' => 'CIH', 'type' => 'bank',
            'accepted_methods' => ['bank_transfer', 'card'],
        ]);

        $this->expectException(ValidationException::class);
        $this->recordPayment($owner, $order, $account, '5.0000', ['method' => 'cheque']);
    }

    public function test_creating_an_account_via_http_persists_its_accepted_methods(): void
    {
        [$owner, $organization] = $this->paymentFixture();
        $this->actingAs($owner)->post(route('financial-accounts.store'), [
            'name' => 'Banque Attijari', 'code' => 'ATTIJARI', 'type' => 'bank', 'status' => 'active', 'currency_code' => 'MAD',
            'accepted_methods' => ['bank_transfer'],
        ])->assertRedirect();

        $account = FinancialAccount::query()->where('code', 'ATTIJARI')->firstOrFail();
        $this->assertSame(['bank_transfer'], $account->accepted_methods);
    }

    public function test_creating_an_account_via_http_requires_at_least_one_accepted_method(): void
    {
        [$owner] = $this->paymentFixture();
        $this->actingAs($owner)->postJson(route('financial-accounts.store'), [
            'name' => 'Sans méthode', 'code' => 'NOMETHOD', 'type' => 'other', 'status' => 'active', 'currency_code' => 'MAD',
            'accepted_methods' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('accepted_methods');
    }

    /**
     * The multi-method migration backfilled every existing account's
     * `accepted_methods` from the exact type => methods mapping
     * RecordPaymentAction::assertCompatible() used to check — so no
     * pre-existing account gained or lost compatibility, and every payment
     * ever recorded against it remains valid.
     */
    public function test_migration_backfill_preserves_the_exact_prior_type_based_compatibility(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture();
        $cash = $this->createFinancialAccount($organization, ['name' => 'Old Cash', 'code' => 'OLDCASH', 'type' => 'cash']);
        $bank = $this->createFinancialAccount($organization, ['name' => 'Old Bank', 'code' => 'OLDBANK', 'type' => 'bank']);
        $cardClearing = $this->createFinancialAccount($organization, ['name' => 'Old Card', 'code' => 'OLDCARD', 'type' => 'card_clearing']);
        $chequeClearing = $this->createFinancialAccount($organization, ['name' => 'Old Cheque', 'code' => 'OLDCHEQUE', 'type' => 'cheque_clearing']);

        $this->assertTrue($cash->acceptsMethod(\App\Enums\PaymentMethod::Cash));
        $this->assertFalse($cash->acceptsMethod(\App\Enums\PaymentMethod::Card));

        $this->assertTrue($bank->acceptsMethod(\App\Enums\PaymentMethod::Card));
        $this->assertTrue($bank->acceptsMethod(\App\Enums\PaymentMethod::BankTransfer));
        $this->assertFalse($bank->acceptsMethod(\App\Enums\PaymentMethod::Cash));
        $this->assertFalse($bank->acceptsMethod(\App\Enums\PaymentMethod::Cheque));

        $this->assertTrue($cardClearing->acceptsMethod(\App\Enums\PaymentMethod::Card));
        $this->assertFalse($cardClearing->acceptsMethod(\App\Enums\PaymentMethod::BankTransfer));

        $this->assertTrue($chequeClearing->acceptsMethod(\App\Enums\PaymentMethod::Cheque));
        $this->assertFalse($chequeClearing->acceptsMethod(\App\Enums\PaymentMethod::Cash));

        // And the exact payments that always worked still work.
        $this->recordPayment($owner, $order, $bank, '5.0000', ['method' => 'card']);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_foreign_organizations_account_configuration_never_affects_compatibility_here(): void
    {
        [$owner, $organization, , $order] = $this->paymentFixture();
        [, $otherOrganization] = $this->paymentFixture();

        $ownAccount = $this->createFinancialAccount($organization, ['name' => 'Caisse', 'code' => 'CAISSE-A', 'accepted_methods' => ['cash']]);
        $this->createFinancialAccount($otherOrganization, ['name' => 'Caisse B', 'code' => 'CAISSE-B', 'accepted_methods' => ['cash', 'card', 'bank_transfer', 'cheque']]);

        // Organization A's own account is still limited to exactly what it
        // was configured with — a permissive account in an unrelated
        // organization changes nothing here.
        $this->expectException(ValidationException::class);
        $this->recordPayment($owner, $order, $ownAccount, '5.0000', ['method' => 'card']);
    }

    private function accountPayload(): array
    {
        return ['name' => 'Forged', 'code' => 'FORGED', 'type' => 'cash', 'status' => 'active', 'currency_code' => 'MAD', 'accepted_methods' => ['cash']];
    }

    private function paymentPayload(int $accountId, string $amount, string $date): array
    {
        return ['method' => 'cash', 'financial_account_id' => $accountId, 'amount' => $amount, 'payment_date' => $date];
    }
}
