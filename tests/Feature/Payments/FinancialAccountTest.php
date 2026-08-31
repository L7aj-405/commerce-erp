<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\ManageFinancialAccountAction;
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

    private function accountPayload(): array
    {
        return ['name' => 'Forged', 'code' => 'FORGED', 'type' => 'cash', 'status' => 'active', 'currency_code' => 'MAD'];
    }

    private function paymentPayload(int $accountId, string $amount, string $date): array
    {
        return ['method' => 'cash', 'financial_account_id' => $accountId, 'amount' => $amount, 'payment_date' => $date];
    }
}
