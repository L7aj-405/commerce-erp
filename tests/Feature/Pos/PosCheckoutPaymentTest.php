<?php

namespace Tests\Feature\Pos;

use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class PosCheckoutPaymentTest extends PosTestCase
{
    public function test_full_cash_checkout_records_due_not_cash_received_and_returns_change(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext();
        $payload = $this->posPayload($warehouse, [$line], ['payments' => [
            $this->posPayment($cash, '100.0000', ['cash_received' => '150.0000']),
        ]]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('pos.completed_tenders.0.change', '50.0000');

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('100.0000', $payment->amount);
        $this->assertSame('cash', $payment->method->value);
        $this->assertSame('paid', SalesOrder::query()->firstOrFail()->payment_status->value);
    }

    public function test_partial_delivery_cash_payment_preserves_paid_and_remaining_amounts(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext('935.0000');
        $payload = $this->posPayload($warehouse, [$line], [
            'fulfillment_mode' => 'delivery',
            'payments' => [$this->posPayment($cash, '500.0000', ['cash_received' => '500.0000'])],
        ]);

        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect(route('pos.index'));

        $order = SalesOrder::query()->firstOrFail();
        $payment = Payment::query()->firstOrFail();

        $this->assertSame('500.0000', $payment->amount);
        $this->assertSame('partially_paid', $order->payment_status->value);
        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('completedOrder.total_incl_tax', '935.0000')
            ->where('completedOrder.paid', '500.0000')
            ->where('completedOrder.remaining', '435.0000')
            ->has('completedOrder.payments', 1)
            ->where('completedOrder.payments.0.amount', '500.0000')
            ->where('completedOrder.payments.0.cash_received', '500.0000')
            ->where('completedOrder.payments.0.change', '0.0000'));
    }

    public function test_full_card_tpe_checkout_uses_card_clearing_account(): void
    {
        [$owner, $organization, , $warehouse, $line] = $this->checkoutContext();
        $account = $this->createPosAccount($organization, 'card_clearing', 'TPE', 'TPE Main');
        $this->checkout($owner, $warehouse, $line, [$this->posPayment($account, '100.0000', ['method' => 'card', 'cash_received' => null, 'reference' => 'TPE-1'])]);
        $this->assertDatabaseHas('payments', ['method' => 'card', 'financial_account_id' => $account->id, 'amount' => 100, 'reference' => 'TPE-1']);
    }

    public function test_bank_transfer_checkout_uses_bank_account(): void
    {
        [$owner, $organization, , $warehouse, $line] = $this->checkoutContext();
        $account = $this->createPosAccount($organization, 'bank', 'BANK', 'Main Bank');
        $this->checkout($owner, $warehouse, $line, [$this->posPayment($account, '100.0000', ['method' => 'bank_transfer', 'cash_received' => null, 'reference' => 'BANK-1'])]);
        $this->assertDatabaseHas('payments', ['method' => 'bank_transfer', 'financial_account_id' => $account->id, 'reference' => 'BANK-1']);
    }

    public function test_cheque_checkout_posts_to_cheque_clearing(): void
    {
        [$owner, $organization, , $warehouse, $line] = $this->checkoutContext();
        $account = $this->createPosAccount($organization, 'cheque_clearing', 'CHEQUE', 'Cheque Clearing');
        $this->checkout($owner, $warehouse, $line, [$this->posPayment($account, '100.0000', ['method' => 'cheque', 'cash_received' => null, 'reference' => 'CH-42'])]);
        $this->assertDatabaseHas('payments', ['method' => 'cheque', 'financial_account_id' => $account->id, 'status' => 'posted']);
    }

    public function test_cash_and_card_split_is_one_paid_fulfilled_checkout(): void
    {
        [$owner, $organization, , $warehouse, $line, $cash] = $this->checkoutContext('935.0000');
        $card = $this->createPosAccount($organization, 'card_clearing', 'TPE', 'TPE Main');
        $response = $this->checkout($owner, $warehouse, $line, [
            $this->posPayment($cash, '500.0000', ['cash_received' => '500.0000']),
            $this->posPayment($card, '435.0000', ['method' => 'card', 'cash_received' => null]),
        ]);
        $response->assertSessionHas('pos.completed_tenders.0.change', '0.0000');

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame('fulfilled', $order->fulfillment_status->value);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseHas('payments', ['method' => 'cash', 'amount' => 500]);
        $this->assertDatabaseHas('payments', ['method' => 'card', 'amount' => 435]);
    }

    public function test_three_way_split_supports_arbitrary_payment_rows(): void
    {
        [$owner, $organization, , $warehouse, $line, $cash] = $this->checkoutContext('1000.0000');
        $card = $this->createPosAccount($organization, 'card_clearing', 'TPE', 'TPE Main');
        $bank = $this->createPosAccount($organization, 'bank', 'BANK', 'Bank');
        $this->checkout($owner, $warehouse, $line, [
            $this->posPayment($cash, '200.0000'),
            $this->posPayment($card, '300.0000', ['method' => 'card', 'cash_received' => null]),
            $this->posPayment($bank, '500.0000', ['method' => 'bank_transfer', 'cash_received' => null]),
        ]);
        $this->assertDatabaseCount('payments', 3);
        $this->assertDatabaseCount('payment_allocations', 3);
    }

    public function test_insufficient_cash_received_rejects_entire_checkout(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext('935.0000');
        $payload = $this->posPayload($warehouse, [$line], ['payments' => [
            $this->posPayment($cash, '500.0000', ['cash_received' => '400.0000']),
        ]]);
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_tender_can_exceed_payment_amount_without_affecting_payment_recorded(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext('935.0000');
        $payload = $this->posPayload($warehouse, [$line], [
            'fulfillment_mode' => 'delivery',
            'payments' => [$this->posPayment($cash, '500.0000', ['cash_received' => '600.0000'])],
        ]);

        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('pos.completed_tenders.0.change', '100.0000');

        $order = SalesOrder::query()->firstOrFail();
        $payment = Payment::query()->firstOrFail();

        $this->assertSame('500.0000', $payment->amount);
        $this->assertSame('partially_paid', $order->payment_status->value);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_partial_delivery_payment_is_recorded_and_order_remains_confirmed_with_balance_due(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext();
        $payload = $this->posPayload($warehouse, [$line], [
            'fulfillment_mode' => 'delivery',
            'payments' => [$this->posPayment($cash, '50.0000')],
        ]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect(route('pos.index'));

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('partially_paid', $order->payment_status->value);
        $this->assertNotSame('fulfilled', $order->fulfillment_status->value);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_pickup_checkout_rejects_partial_payment_before_fulfillment(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext();
        $payload = $this->posPayload($warehouse, [$line], [
            'payments' => [$this->posPayment($cash, '50.0000')],
        ]);

        $this->actingAs($owner)->postJson(route('pos.sales.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments');

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_overpayment_is_rejected_instead_of_becoming_customer_credit(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext();
        $payload = $this->posPayload($warehouse, [$line], ['payments' => [
            $this->posPayment($cash, '150.0000', ['cash_received' => '150.0000']),
        ]]);
        $this->actingAs($owner)->postJson(route('pos.sales.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_success_page_uses_authoritative_paid_remaining_and_payment_breakdown(): void
    {
        [$owner, , , $warehouse, $line, $cash] = $this->checkoutContext();
        $this->checkout($owner, $warehouse, $line, [
            $this->posPayment($cash, '100.0000', ['cash_received' => '120.0000']),
        ]);
        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('completedOrder.total_incl_tax', '100.0000')
            ->where('completedOrder.paid', '100.0000')
            ->where('completedOrder.remaining', '0.0000')
            ->where('completedOrder.payment_status', 'paid')
            ->where('completedOrder.fulfillment_status', 'fulfilled')
            ->has('completedOrder.payments', 1)
            ->where('completedOrder.payments.0.change', '20.0000'));
    }

    /** @return array{User, Organization, Store, Warehouse, array<string, mixed>, FinancialAccount} */
    private function checkoutContext(string $total = '100.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $this->activate($owner, $organization, $store);
        $cash = $this->createPosAccount($organization, 'cash', 'CASH', 'Main Cash');

        return [$owner, $organization, $store, $warehouse, $this->posCustomLine(['unit_price_excl_tax' => $total]), $cash];
    }

    /** @param list<array<string, mixed>> $payments */
    private function checkout(User $owner, $warehouse, array $line, array $payments)
    {
        return $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$line], ['payments' => $payments]))->assertRedirect(route('pos.index'));
    }
}
