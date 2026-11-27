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
use App\Models\Fulfillment;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

/**
 * Step 5 UAT remediation: (1) a handed-over exchange replacement must read as delivered everywhere (order
 * fulfilment panel, engine line state); (2) a cancelled return / exchange presents no actionable balance.
 */
class ExchangeUatRemediationTest extends PosTestCase
{
    public function test_completed_exchange_replacement_reads_as_delivered_on_the_order_without_new_stock_or_rows(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'UAT-NEW', '1300.0000', '2.0000');
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $new->id));
        $this->assertSame('awaiting_settlement', $exchange->status); // delivered, still unpaid
        $movements = InventoryMovement::query()->count();
        $fulfillments = Fulfillment::query()->count();

        $this->assertOrderReadsDelivered($owner, $order);

        $this->pay($owner, $exchange, Payment::query()->firstOrFail()->financialAccount, '300.0000');
        $this->assertSame('completed', $exchange->fresh()->status);
        $this->assertOrderReadsDelivered($owner, $order);

        $this->assertSame($fulfillments, Fulfillment::query()->count(), 'No fulfilment row is invented for the addendum.');
        $this->assertSame($movements, InventoryMovement::query()->count(), 'Reading the order or settling must not move stock.');
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $new->id, 'on_hand' => 1, 'reserved' => 0]);
        $this->assertSame('0.0000', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));
    }

    public function test_cheaper_completed_exchange_also_reads_as_delivered(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'UAT-CHEAP', '700.0000', '2.0000');
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $new->id));
        $payment = Payment::query()->whereHas('allocations', fn ($q) => $q->where('sales_order_id', $order->id))->sole();
        app(SettleCustomerExchangeRefundAction::class)->execute($owner, $exchange, $payment, '300.0000', 'Solde', (string) Str::uuid());

        $this->assertSame('completed', $exchange->fresh()->status);
        $this->assertOrderReadsDelivered($owner, $order);
    }

    public function test_the_handed_over_replacement_cannot_be_shipped_or_fulfilled_a_second_time(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'UAT-NODUP', '1300.0000', '2.0000');
        $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $new->id));
        $movements = InventoryMovement::query()->count();

        $engine = app(FulfillmentEngine::class);
        $this->assertSame([], $engine->remaining($order->fresh()), 'Nothing is left to ship: the replacement was already handed over.');
        $this->assertSame('fulfilled', $engine->syncOrder($owner, $order->fresh())->fulfillment_status->value, 'A re-sync must keep the order fulfilled.');
        $this->assertSame($movements, InventoryMovement::query()->count());
        $this->assertSame(1, Fulfillment::query()->count());
    }

    public function test_an_unfulfilled_exchange_does_not_add_a_delivered_replacement_line(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'UAT-PENDING', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);

        $this->actingAs($owner)->get(route('sales.orders.show', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('fulfillment.delivery_state', 'delivered')
            ->has('fulfillment.lines', 1)); // only the original, already delivered line exists yet
    }

    public function test_cancelled_return_and_exchange_show_no_actionable_balance_but_keep_history(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'UAT-CXL', '1300.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        $return = $exchange->customerReturn;
        $paymentsBefore = Payment::query()->count();
        app(CancelCustomerExchangeAction::class)->execute($owner, $exchange, 'Client parti');

        $this->actingAs($owner)->get(route('sales.returns.show', $return))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customerReturn.status', 'cancelled')
            ->where('refundSummary.refunded', '0.0000')
            ->where('refundSummary.remaining', '0.0000')
            ->where('creditSummary.issued', '0.0000'));
        $this->actingAs($owner)->get(route('sales.exchanges.show', $exchange))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('exchange.status', 'cancelled')
            ->where('settlementSummary.target', '0.0000')
            ->where('settlementSummary.remaining', '0.0000'));

        // History is preserved: planned amounts and audit trail are untouched; nothing was posted.
        $this->assertSame('1000.0000', (string) $return->fresh()->total_incl_tax);
        $this->assertSame('300.0000', (string) $exchange->fresh()->difference_amount);
        $this->assertSame(1, AuditLog::query()->where('event', 'sales_exchange.cancelled')->count());
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_a_received_return_still_shows_its_valid_remaining_refund(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'UAT-KEEP', '700.0000', '2.0000');
        $exchange = $this->createExchange($owner, $order, $new->id);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);

        $this->actingAs($owner)->get(route('sales.returns.show', $exchange->customerReturn))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customerReturn.status', 'received')
            ->where('refundSummary.remaining', '1000.0000'));
        $this->actingAs($owner)->get(route('sales.exchanges.show', $exchange))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('settlementSummary.target', '300.0000')
            ->where('settlementSummary.remaining', '300.0000'));
    }

    public function test_other_tenants_cannot_read_the_exchange_return_or_order_pages(): void
    {
        [$owner, , , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $new = $this->variant($owner, $warehouse, 'UAT-TENANT', '1300.0000', '2.0000');
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $new->id));

        $attacker = User::factory()->create();
        $other = $this->createOrganization($attacker);
        $otherStore = $this->createStore($other, $attacker);
        $this->activate($attacker, $other, $otherStore);
        $this->actingAs($attacker)->get(route('sales.exchanges.show', $exchange))->assertNotFound();
        $this->actingAs($attacker)->get(route('sales.returns.show', $exchange->customerReturn))->assertNotFound();
        $this->actingAs($attacker)->get(route('sales.orders.show', $order))->assertNotFound();
    }

    private function assertOrderReadsDelivered(User $owner, SalesOrder $order): void
    {
        $this->actingAs($owner)->get(route('sales.orders.show', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.fulfillment_status', 'fulfilled')
            ->where('fulfillment.state', 'fulfilled')
            ->where('fulfillment.delivery_state', 'delivered')
            ->where('fulfillment.logistics_state', 'delivered')
            ->has('fulfillment.lines', 2)
            ->where('fulfillment.lines.0.remaining', '0.0000')
            ->where('fulfillment.lines.1.remaining', '0.0000')
            ->where('fulfillment.lines.1.delivered', '1.0000')
            ->where('fulfillment.lines.1.shippable', '0.0000')
            ->where('fulfillment.actions', []));
    }

    private function pay(User $owner, CustomerExchange $exchange, $account, string $amount): CustomerExchange
    {
        return app(SettleCustomerExchangePaymentAction::class)->execute($owner, $exchange, [[
            'method' => 'cash', 'financial_account_id' => $account->id, 'amount' => $amount,
            'payment_date' => now()->toDateString(), 'reference' => 'UAT',
        ]], (string) Str::uuid());
    }

    private function variant(User $owner, $warehouse, string $sku, string $price, string $stock)
    {
        $organization = $warehouse->organization;
        $variant = $this->createProduct($organization, $sku, $sku, ['default_sale_price' => $price])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return $variant;
    }

    /** @return array{User,mixed,mixed,mixed,mixed,SalesOrder} */
    private function sale(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $organization->settings = $settings;
        $organization->save();
        $variant = $this->createProduct($organization, 'Product A', 'UAT-A', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '2.0000');
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant)]))->assertRedirect();
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
