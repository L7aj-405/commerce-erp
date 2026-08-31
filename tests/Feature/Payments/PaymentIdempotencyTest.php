<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

class PaymentIdempotencyTest extends PaymentTestCase
{
    public function test_same_operation_retry_returns_original_payment_without_duplicate(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $operation = (string) Str::uuid();
        $first = $this->recordPayment($owner, $order, $account, '25.0000', ['client_operation_id' => $operation]);
        $retry = $this->recordPayment($owner, $order, $account, '25.0000', ['client_operation_id' => $operation]);
        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_operation_retry_with_changed_data_is_rejected(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $operation = (string) Str::uuid();
        $this->recordPayment($owner, $order, $account, '25.0000', ['client_operation_id' => $operation]);
        $this->expectException(ValidationException::class);
        $this->recordPayment($owner, $order, $account, '20.0000', ['client_operation_id' => $operation]);
    }

    public function test_split_payment_retry_is_idempotent_per_sequence(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $operation = (string) Str::uuid();
        $entries = [$this->entry($account->id, '30.0000'), $this->entry($account->id, '20.0000')];
        $action = app(RecordSalesOrderPaymentsAction::class);
        $first = $action->execute($owner, $order, $entries, $operation);
        $retry = $action->execute($owner, $order, $entries, $operation);
        $this->assertSame($first->pluck('id')->all(), $retry->pluck('id')->all());
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_split_retry_cannot_change_entry_count(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $operation = (string) Str::uuid();
        $action = app(RecordSalesOrderPaymentsAction::class);
        $action->execute($owner, $order, [$this->entry($account->id, '10.0000')], $operation);
        $this->expectException(ValidationException::class);
        $action->execute($owner, $order, [$this->entry($account->id, '10.0000'), $this->entry($account->id, '10.0000')], $operation);
    }

    public function test_posted_payment_has_no_update_or_delete_endpoint(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000');
        $this->actingAs($owner)->patch("/payments/{$payment->id}", ['amount' => '0.0000'])->assertStatus(405);
        $this->actingAs($owner)->delete("/payments/{$payment->id}")->assertStatus(405);

        $unchanged = $payment->fresh();
        $this->assertSame('10.0000', $unchanged->amount);
        $this->assertSame('posted', $unchanged->status->value);
    }

    /** @return array<string, mixed> */
    private function entry(int $accountId, string $amount): array
    {
        return ['method' => 'cash', 'financial_account_id' => $accountId, 'amount' => $amount, 'payment_date' => now()->toDateString()];
    }
}
