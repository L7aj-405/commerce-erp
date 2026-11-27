<?php

namespace Tests\Feature\Exchanges;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Exchanges\CancelCustomerExchangeAction;
use App\Actions\Exchanges\CreateCustomerExchangeAction;
use App\Actions\Exchanges\FulfillCustomerExchangeReplacementAction;
use App\Actions\Exchanges\ReceiveCustomerExchangeReturnAction;
use App\Actions\Exchanges\SettleCustomerExchangePaymentAction;
use App\Actions\Exchanges\SettleCustomerExchangeRefundAction;
use App\Models\AuditLog;
use App\Models\CustomerExchange;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\SalesOrderAddendum;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosTestCase;

/**
 * Phase 1 Step 5 — exchange integrity: each exchange transition is one transaction (F-07 generalised), state is
 * judged under the exchange lock, and repeated submissions are idempotent.
 */
class ExchangeIntegrityTest extends PosTestCase
{
    // ---------------------------------------------------------------- atomicity (F-07)

    public function test_payment_settlement_rolls_back_the_posted_payments_when_the_completion_fails(): void
    {
        [$owner, , , , $exchange, $account] = $this->awaitingPaymentExchange();
        $paymentsBefore = Payment::query()->count();
        $this->failWhenExchangeBecomes('completed');

        try {
            $this->pay($owner, $exchange, $account, '300.0000');
            $this->fail('The injected failure must surface.');
        } catch (RuntimeException) {
            $this->assertSame($paymentsBefore, Payment::query()->count(), 'Payments posted before the failure must roll back.');
            $this->assertSame(0, DB::table('customer_exchange_payments')->count(), 'No orphan link.');
            $this->assertSame('awaiting_settlement', $exchange->fresh()->status);
            $this->assertFalse(AuditLog::query()->where('event', 'sales_exchange.settlement_recorded')->exists());
        }
    }

    public function test_refund_settlement_rolls_back_the_refund_when_the_completion_fails(): void
    {
        [$owner, , , , $exchange, , $payment] = $this->awaitingRefundExchange();
        $this->failWhenExchangeBecomes('completed');

        try {
            app(SettleCustomerExchangeRefundAction::class)->execute($owner, $exchange, $payment, '300.0000', 'Solde', (string) Str::uuid());
            $this->fail('The injected failure must surface.');
        } catch (RuntimeException) {
            $this->assertSame(0, PaymentRefund::query()->count());
            $this->assertSame('awaiting_settlement', $exchange->fresh()->status);
        }
    }

    public function test_return_reception_rolls_back_stock_and_credit_note_when_the_exchange_transition_fails(): void
    {
        [$owner, , , $warehouse, $old, $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-RCV', '1000.0000', '1.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        $movements = InventoryMovement::query()->count();
        $this->failWhenExchangeBecomes('awaiting_replacement');

        try {
            app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
            $this->fail('The injected failure must surface.');
        } catch (RuntimeException) {
            $this->assertSame($movements, InventoryMovement::query()->count(), 'Restocked quantity must roll back.');
            $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $old->id, 'on_hand' => 1]);
            $this->assertSame('draft', $exchange->customerReturn->fresh()->status);
            $this->assertSame('awaiting_return_receipt', $exchange->fresh()->status);
            $this->assertSame('draft', $exchange->customerReturn->creditNotes()->sole()->status);
        }
    }

    public function test_replacement_fulfilment_rolls_back_stock_and_addendum_when_the_exchange_update_fails(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-FUL', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
        $this->failWhenExchangeBecomes('awaiting_settlement');

        try {
            app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh());
            $this->fail('The injected failure must surface.');
        } catch (RuntimeException) {
            $this->assertSame(0, SalesOrderAddendum::query()->count());
            $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $new->id, 'on_hand' => 2, 'reserved' => 0]);
            $this->assertSame('1000.0000', $order->fresh()->total_incl_tax);
            $this->assertSame('awaiting_replacement', $exchange->fresh()->status);
        }
    }

    public function test_cancellation_rolls_back_the_draft_return_cancellation_when_it_fails(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-CAN', '1000.0000', '1.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        $this->failWhenExchangeBecomes('cancelled');

        try {
            app(CancelCustomerExchangeAction::class)->execute($owner, $exchange, 'Annulation');
            $this->fail('The injected failure must surface.');
        } catch (RuntimeException) {
            $this->assertSame('draft', $exchange->customerReturn->fresh()->status);
            $this->assertSame('awaiting_return_receipt', $exchange->fresh()->status);
        }
    }

    // ---------------------------------------------------------------- repeated submissions

    public function test_repeated_reception_and_fulfilment_are_refused_and_never_duplicate_stock_or_addenda(): void
    {
        [$owner, , , $warehouse, $old, $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-REP', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);

        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
        $afterFirstReceive = InventoryMovement::query()->count();
        try {
            app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange); // stale in-memory copy, double click
            $this->fail('A second reception must be refused.');
        } catch (ValidationException) {
        }
        $this->assertSame($afterFirstReceive, InventoryMovement::query()->count());

        app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh());
        $afterFirstFulfil = InventoryMovement::query()->count();
        try {
            app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange); // stale copy again
            $this->fail('A second delivery must be refused.');
        } catch (ValidationException) {
        }
        $this->assertSame($afterFirstFulfil, InventoryMovement::query()->count());
        $this->assertSame(1, SalesOrderAddendum::query()->count());
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $new->id, 'on_hand' => 1, 'reserved' => 0]);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $old->id, 'on_hand' => 2]);
        $this->assertSame(1, AuditLog::query()->where('event', 'sales_exchange.return_received')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'sales_exchange.replacement_fulfilled')->count());
    }

    public function test_repeated_payment_submission_is_idempotent_even_after_completion(): void
    {
        [$owner, , , , $exchange, $account] = $this->awaitingPaymentExchange();
        $operation = (string) Str::uuid();
        $first = $this->pay($owner, $exchange, $account, '300.0000', $operation);
        $this->assertSame('completed', $first->status);

        $second = $this->pay($owner, $exchange, $account, '300.0000', $operation); // browser retry
        $this->assertSame('completed', $second->status);
        $this->assertSame(1, Payment::query()->where('client_operation_id', $operation)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'sales_exchange.completed')->count());

        $this->expectException(ValidationException::class); // same id, different payload
        $this->pay($owner, $exchange, $account, '299.0000', $operation);
    }

    public function test_repeated_refund_submission_is_idempotent_even_after_completion(): void
    {
        [$owner, , , , $exchange, , $payment] = $this->awaitingRefundExchange();
        $operation = (string) Str::uuid();
        $action = app(SettleCustomerExchangeRefundAction::class);
        $action->execute($owner, $exchange, $payment, '300.0000', 'Solde', $operation);
        $again = $action->execute($owner, $exchange, $payment, '300.0000', 'Solde', $operation);

        $this->assertSame('completed', $again->status);
        $this->assertSame(1, PaymentRefund::query()->count());
        $this->assertSame('300.0000', (string) PaymentRefund::query()->sole()->amount);
    }

    // ---------------------------------------------------------------- stale reads (state judged under the lock)

    public function test_a_stale_cancellation_cannot_undo_a_delivered_exchange(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-STALE', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
        $stale = $exchange->fresh(); // the cancelling session still sees "awaiting_replacement"
        app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh());

        try {
            app(CancelCustomerExchangeAction::class)->execute($owner, $stale, 'Trop tard');
            $this->fail('A delivered exchange must not be cancellable from a stale view.');
        } catch (ValidationException) {
            $this->assertSame('awaiting_settlement', $exchange->fresh()->status);
            $this->assertNull($exchange->fresh()->cancelled_at);
            $this->assertFalse(AuditLog::query()->where('event', 'sales_exchange.cancelled')->exists());
        }
    }

    public function test_a_stale_fulfilment_cannot_resurrect_a_cancelled_exchange(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-CXL', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
        $stale = $exchange->fresh();
        app(CancelCustomerExchangeAction::class)->execute($owner, $exchange->fresh(), 'Annulé');

        try {
            app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $stale);
            $this->fail('A cancelled exchange must not deliver goods.');
        } catch (ValidationException) {
            $this->assertSame(0, SalesOrderAddendum::query()->count());
            $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $new->id, 'on_hand' => 2]);
            $this->assertSame('cancelled', $exchange->fresh()->status);
        }
    }

    public function test_a_stale_payment_cannot_overpay_the_difference(): void
    {
        [$owner, , , , $exchange, $account] = $this->awaitingPaymentExchange();
        $stale = $exchange->fresh();
        $this->pay($owner, $exchange, $account, '200.0000');

        try {
            $this->pay($owner, $stale, $account, '200.0000'); // 400 > 300 although the stale copy still shows 300 remaining
            $this->fail('The difference must be enforced from the locked row.');
        } catch (ValidationException) {
            $this->assertSame('200.0000', (string) Payment::query()->where('amount', 200)->sole()->amount);
            $this->assertSame(2, Payment::query()->count()); // original sale payment + one 200 exchange payment
        }
        $done = $this->pay($owner, $exchange->fresh(), $account, '100.0000');
        $this->assertSame('completed', $done->status);
        $this->expectException(ValidationException::class);
        $this->pay($owner, $stale, $account, '1.0000'); // completed exchange accepts nothing more
    }

    public function test_refunds_never_exceed_the_exchange_target_even_with_a_stale_view(): void
    {
        [$owner, , , , $exchange, , $payment] = $this->awaitingRefundExchange();
        $stale = $exchange->fresh();
        $action = app(SettleCustomerExchangeRefundAction::class);
        $action->execute($owner, $exchange, $payment, '200.0000', 'Partiel', (string) Str::uuid());

        try {
            $action->execute($owner, $stale, $payment, '200.0000', 'Doublon', (string) Str::uuid());
            $this->fail('Target is 300: 200 + 200 must be refused.');
        } catch (ValidationException) {
            $this->assertSame(1, PaymentRefund::query()->count());
        }
        $done = $action->execute($owner, $exchange->fresh(), $payment, '100.0000', 'Solde', (string) Str::uuid());
        $this->assertSame('completed', $done->status);
        $this->assertSame('300.0000', Decimal4::sum(PaymentRefund::query()->pluck('amount')->all()));
    }

    // ---------------------------------------------------------------- stock at fulfilment

    public function test_insufficient_replacement_stock_at_fulfilment_changes_nothing(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-OOS', '1000.0000', '1.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
        // Another sale takes the only replacement unit between creation and delivery.
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($new)]))->assertRedirect();
        $movements = InventoryMovement::query()->count();

        try {
            app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh());
            $this->fail('No stock: fulfilment must be refused.');
        } catch (ValidationException) {
            $this->assertSame($movements, InventoryMovement::query()->count());
            $this->assertSame(0, SalesOrderAddendum::query()->count());
            $this->assertSame('awaiting_replacement', $exchange->fresh()->status);
            $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $new->id, 'on_hand' => 0, 'reserved' => 0]);
        }
    }

    public function test_multiple_quantities_keep_exact_precision_and_stock(): void
    {
        [$owner, , , $warehouse, $old, $order] = $this->sale(stock: '5.0000', quantity: '3.0000');
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-QTY', '1333.3300', '5.0000');
        $exchange = app(CreateCustomerExchangeAction::class)->execute($owner, $order->fresh(), (string) Str::uuid(), [
            ['sales_order_line_id' => $order->lines()->firstOrFail()->id, 'quantity' => '2.0000'],
        ], [['product_variant_id' => $new->id, 'quantity' => '2.0000']], 'Échange', 'restock');

        $this->assertSame('2000.0000', $exchange->returned_total);
        $expectedNew = $exchange->new_items_total;
        $this->assertSame(bcsub($expectedNew, '2000.0000', 4), $exchange->difference_amount);
        $exchange = $this->receiveAndFulfill($owner, $exchange);
        $this->assertSame($expectedNew, $exchange->new_items_total);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $old->id, 'on_hand' => 4]);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $new->id, 'on_hand' => 3]);
    }

    // ---------------------------------------------------------------- closed periods (F-03 unchanged)

    public function test_closed_month_refuses_reception_and_settlement_without_any_partial_write(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-CLS', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        $this->closeCurrentMonth($organization);
        $movements = InventoryMovement::query()->count();

        try {
            app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
            $this->fail('Credit note dated in a closed month must be refused.');
        } catch (ValidationException) {
            $this->assertSame($movements, InventoryMovement::query()->count());
            $this->assertSame('awaiting_return_receipt', $exchange->fresh()->status);
            $this->assertSame('draft', $exchange->customerReturn->fresh()->status);
        }

        DB::table('finance_month_reviews')->where('organization_id', $organization->id)->delete();
        $this->receiveAndFulfill($owner, $exchange->fresh());
        $this->closeCurrentMonth($organization);
        $payments = Payment::query()->count();
        $account = Payment::query()->firstOrFail()->financialAccount;
        try {
            $this->pay($owner, $exchange->fresh(), $account, '300.0000');
            $this->fail('Payment in a closed month must be refused.');
        } catch (ValidationException) {
            $this->assertSame($payments, Payment::query()->count());
            $this->assertSame(0, DB::table('customer_exchange_payments')->count());
            $this->assertSame('awaiting_settlement', $exchange->fresh()->status);
        }
    }

    // ---------------------------------------------------------------- permissions and isolation

    public function test_every_transition_requires_the_process_permission_and_the_active_store(): void
    {
        [$owner, $organization, $store, $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-PERM', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);

        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['sales_exchanges.view'], roleName: 'Exchange Viewer');
        $this->addStoreMember($store, $viewer);
        $this->activate($viewer, $organization, $store);
        foreach ([
            fn () => app(ReceiveCustomerExchangeReturnAction::class)->execute($viewer, $exchange),
            fn () => app(FulfillCustomerExchangeReplacementAction::class)->execute($viewer, $exchange),
            fn () => app(CancelCustomerExchangeAction::class)->execute($viewer, $exchange, 'x'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Missing sales_exchanges.process must be refused.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $otherStore = $this->createStore($organization, $owner, 'Autre boutique');
        $this->activate($owner, $organization, $otherStore);
        try {
            app(ReceiveCustomerExchangeReturnAction::class)->execute($owner->fresh(), $exchange);
            $this->fail('An exchange of another store must be invisible.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame('awaiting_return_receipt', $exchange->fresh()->status);
    }

    public function test_full_flow_writes_one_audit_entry_per_transition(): void
    {
        [$owner, , , , $exchange, $account] = $this->awaitingPaymentExchange();
        $this->pay($owner, $exchange, $account, '300.0000');

        foreach (['sales_exchange.created', 'sales_exchange.return_received', 'sales_exchange.replacement_fulfilled',
            'sales_exchange.settlement_recorded', 'sales_exchange.settled', 'sales_exchange.completed'] as $event) {
            $this->assertSame(1, AuditLog::query()->where('event', $event)->count(), $event);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function failWhenExchangeBecomes(string $status): void
    {
        CustomerExchange::saving(function (CustomerExchange $exchange) use ($status) {
            if ($exchange->status === $status && $exchange->isDirty('status')) {
                throw new RuntimeException('injected failure');
            }
        });
    }

    private function pay(User $owner, CustomerExchange $exchange, $account, string $amount, ?string $operation = null): CustomerExchange
    {
        return app(SettleCustomerExchangePaymentAction::class)->execute($owner, $exchange, [[
            'method' => 'cash', 'financial_account_id' => $account->id, 'amount' => $amount,
            'payment_date' => now()->toDateString(), 'reference' => 'EX5',
        ]], $operation ?? (string) Str::uuid());
    }

    private function closeCurrentMonth($organization): void
    {
        DB::table('finance_month_reviews')->insert([
            'organization_id' => $organization->id, 'period' => now()->format('Y-m').'-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function variant(User $owner, $warehouse, string $sku, string $price, string $stock)
    {
        $organization = $warehouse->organization;
        $variant = $this->createProduct($organization, $sku, $sku, ['default_sale_price' => $price])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return $variant;
    }

    /** @return array{User,mixed,mixed,mixed,CustomerExchange,mixed} owes the customer 300 */
    private function awaitingPaymentExchange(): array
    {
        [$owner, $organization, $store, $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-PAY', '1300.0000', '2.0000');
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $new->id));
        $this->assertSame('300.0000', $exchange->difference_amount);

        return [$owner, $organization, $store, $warehouse, $exchange, Payment::query()->firstOrFail()->financialAccount];
    }

    /** @return array{User,mixed,mixed,mixed,CustomerExchange,mixed,Payment} the shop owes the customer 300 */
    private function awaitingRefundExchange(): array
    {
        [$owner, $organization, $store, $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'EX5-REF', '700.0000', '2.0000');
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $new->id));
        $this->assertSame('-300.0000', $exchange->difference_amount);

        return [$owner, $organization, $store, $warehouse, $exchange, null,
            Payment::query()->whereHas('allocations', fn ($q) => $q->where('sales_order_id', $order->id))->sole()];
    }

    /** @return array{User,mixed,mixed,mixed,mixed,SalesOrder} */
    private function sale(string $stock = '2.0000', string $quantity = '1.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $organization->settings = $settings;
        $organization->save();
        $variant = $this->createProduct($organization, 'Product A', 'EX5-A', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant, ['quantity' => $quantity])]))->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole()->fresh(['lines']);

        return [$owner, $organization, $store, $warehouse, $variant, $order];
    }

    private function issue(User $owner, SalesOrder $order)
    {
        return app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
    }

    private function createExchange(User $owner, SalesOrder $order, int $replacementVariantId): CustomerExchange
    {
        return app(CreateCustomerExchangeAction::class)->execute($owner, $order->fresh(), (string) Str::uuid(), [
            ['sales_order_line_id' => $order->lines()->whereNull('sales_order_addendum_id')->firstOrFail()->id, 'quantity' => '1.0000'],
        ], [['product_variant_id' => $replacementVariantId, 'quantity' => '1.0000']], 'Échange client', 'restock');
    }

    private function receiveAndFulfill(User $owner, CustomerExchange $exchange): CustomerExchange
    {
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);

        return app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh())->fresh();
    }
}

/** Tiny exact-decimal summing helper for assertions (bcmath). */
final class Decimal4
{
    /** @param list<string|int|float> $values */
    public static function sum(array $values): string
    {
        return array_reduce($values, fn (string $c, $v) => bcadd($c, (string) $v, 4), '0.0000');
    }
}
