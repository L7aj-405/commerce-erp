<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\Payment;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentTestCase;

class PaymentRecordingTest extends PaymentTestCase
{
    public function test_full_payment_posts_allocation_and_marks_order_paid(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $payment = $this->recordPayment($owner, $order, $account, '100.0000');

        $this->assertSame('posted', $payment->status->value);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->assertDatabaseHas('payment_allocations', ['organization_id' => $organization->id, 'payment_id' => $payment->id, 'sales_order_id' => $order->id, 'amount' => 100]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.posted', 'organization_id' => $organization->id, 'store_id' => $store->id]);
    }

    public function test_partial_and_multiple_payments_derive_order_status_from_posted_allocations(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '35.1250');
        $this->assertSame('partially_paid', $order->fresh()->payment_status->value);
        $this->assertSame('64.8750', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));
        $this->recordPayment($owner, $order, $account, '64.8750');
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_decimal_projection_is_exact_to_four_places(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('0.3000');
        $this->recordPayment($owner, $order, $account, '0.1000');
        $this->recordPayment($owner, $order, $account, '0.2000');
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_overpayment_is_rejected_without_mutation(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        try {
            $this->recordPayment($owner, $order, $account, '100.0001');
            $this->fail('Expected overpayment rejection.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame('unpaid', $order->fresh()->payment_status->value);
        }
    }

    public function test_draft_and_cancelled_orders_cannot_receive_payments(): void
    {
        [$owner, $organization, $store, , $account] = $this->paymentFixture();
        $draft = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $draft);
        foreach ([$draft, tap($draft->replicate(), function ($cancelled) use ($organization, $store, $owner) {
            $cancelled->organization_id = $organization->id;
            $cancelled->store_id = $store->id;
            $cancelled->order_number = 'CANCELLED-TEST';
            $cancelled->status = 'cancelled';
            $cancelled->created_by_user_id = $owner->id;
            $cancelled->save();
        })] as $order) {
            try {
                $this->recordPayment($owner, $order, $account, '10.0000');
                $this->fail('Expected lifecycle rejection.');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_can_be_recorded_after_fulfillment(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order);
        $this->recordPayment($owner, $fulfilled, $account, '100.0000');
        $this->assertSame('paid', $fulfilled->fresh()->payment_status->value);
    }

    public function test_split_payment_is_atomic_when_a_later_entry_is_invalid(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        try {
            app(RecordSalesOrderPaymentsAction::class)->execute($owner, $order, [
                $this->entry($account->id, '40.0000'),
                $this->entry($account->id, '70.0000'),
            ], (string) Str::uuid());
            $this->fail('Expected atomic overpayment rejection.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('payment_allocations', 0);
        }
    }

    public function test_valid_cash_and_card_split_posts_individually_and_pays_order(): void
    {
        [$owner, $organization, , $order, $cash] = $this->paymentFixture('1200.0000');
        $card = $this->createFinancialAccount($organization, ['name' => 'TPE Main', 'code' => 'TPE', 'type' => 'card_clearing']);
        $payments = app(RecordSalesOrderPaymentsAction::class)->execute($owner, $order, [
            $this->entry($cash->id, '500.0000'),
            array_replace($this->entry($card->id, '700.0000'), ['method' => 'card']),
        ], (string) Str::uuid());
        $this->assertCount(2, $payments);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->assertDatabaseCount('payment_allocations', 2);
    }

    public function test_payment_business_date_is_independent_from_sale_and_creation_dates(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $businessDate = now()->subMonth()->toDateString();
        $payment = $this->recordPayment($owner, $order, $account, '10.0000', ['payment_date' => $businessDate]);
        $this->assertSame($businessDate, $payment->payment_date->toDateString());
        $this->assertSame('2026-08-24', $order->sale_date->toDateString());
        $this->assertNotSame($payment->created_at->toDateString(), $payment->payment_date->toDateString());
    }

    public function test_payment_number_is_sequential_per_organization(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $first = $this->recordPayment($owner, $order, $account, '10.0000');
        $second = $this->recordPayment($owner, $order, $account, '10.0000');
        $this->assertSame('PAY-000001', $first->payment_number);
        $this->assertSame('PAY-000002', $second->payment_number);
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_second_full_balance_submission_is_revalidated_against_locked_order(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '100.0000');
        try {
            $this->recordPayment($owner, $order, $account, '100.0000');
            $this->fail('Expected remaining balance revalidation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame('paid', $order->fresh()->payment_status->value);
        }
    }

    /** @return array<string, mixed> */
    private function entry(int $accountId, string $amount): array
    {
        return ['method' => 'cash', 'financial_account_id' => $accountId, 'amount' => $amount, 'payment_date' => now()->toDateString()];
    }
}
