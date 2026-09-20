<?php

namespace Tests\Feature\Sales;

use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentTestCase;

class SalesOrderCancellationTest extends PaymentTestCase
{
    public function test_confirmed_unpaid_order_cancels_with_history_and_without_fake_stock_movement(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Cancelable product')->variants->firstOrFail();
        $this->openStock($owner, $organization, $warehouse, $variant, '5.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $movementCount = $organization->inventoryMovements()->count();

        $cancelled = app(CancelSalesOrderAction::class)->execute($owner, $order, 'Client a changé d’avis');

        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertSame('Client a changé d’avis', $cancelled->cancellation_reason);
        $this->assertSame($owner->id, $cancelled->cancelled_by_user_id);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($movementCount, $organization->inventoryMovements()->count());
        $this->assertSame('0.0000', $this->balance($organization, $warehouse, $variant)->reserved);
        $this->assertDatabaseHas('inventory_reservations', ['organization_id' => $organization->id, 'status' => 'released']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'sales_order.cancelled']);
    }

    public function test_paid_cancellation_records_exact_cash_outflow_without_rewriting_collection(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture('935.0000');
        $payment = $this->recordPayment($owner, $order, $account, '935.0000');

        app(CancelSalesOrderAction::class)->execute($owner, $order, 'Commande en double');

        $refund = PaymentRefund::query()->sole();
        $this->assertSame('posted', $payment->fresh()->status->value);
        $this->assertSame('935.0000', $payment->amount);
        $this->assertSame('935.0000', $refund->amount);
        $this->assertSame($payment->id, $refund->payment_id);
        $this->assertSame($account->id, $refund->financial_account_id);
        $this->assertSame('cash', $refund->method->value);
        $this->assertSame($organization->id, $refund->organization_id);
        $this->assertSame($store->id, $refund->store_id);

        $summary = app(SalesOrderPaymentCalculator::class)->summary($order->fresh());
        $this->assertSame('935.0000', $summary['collected']);
        $this->assertSame('935.0000', $summary['refunded']);
        $this->assertSame('0.0000', $summary['net']);
        $this->assertSame('0.0000', $summary['remaining']);
    }

    public function test_partial_payment_cancellation_refunds_only_the_posted_amount(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('1000.0000');
        $this->recordPayment($owner, $order, $account, '400.0000');

        app(CancelSalesOrderAction::class)->execute($owner, $order, 'Client a changé d’avis');

        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertSame('400.0000', PaymentRefund::query()->sole()->amount);
        $summary = app(SalesOrderPaymentCalculator::class)->summary($order->fresh());
        $this->assertSame('400.0000', $summary['collected']);
        $this->assertSame('400.0000', $summary['refunded']);
        $this->assertSame('0.0000', $summary['net']);
    }

    public function test_duplicate_cancellation_cannot_create_a_second_refund(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '100.0000');
        app(CancelSalesOrderAction::class)->execute($owner, $order, 'Première demande');

        try {
            app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Nouvelle tentative');
            $this->fail('A cancelled order must not be cancelled or refunded twice.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payment_refunds', 1);
            $this->assertSame('posted', $order->paymentAllocations()->firstOrFail()->payment->fresh()->status->value);
        }
    }

    public function test_fulfilled_order_remains_return_territory_even_when_recently_fulfilled(): void
    {
        [$owner, , , $order] = $this->paymentFixture();
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order);

        $this->expectException(ValidationException::class);
        app(CancelSalesOrderAction::class)->execute($owner, $fulfilled, 'Client revenu immédiatement');
    }

    public function test_refund_action_is_idempotent_for_one_payment_allocation(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture('20.0000');
        $payment = $this->recordPayment($owner, $order, $account, '20.0000');
        $action = app(RefundPaymentAction::class);

        $first = $action->execute($owner, $payment, $order, 'Remboursement autorisé');
        $second = $action->execute($owner, $payment->fresh(), $order->fresh(), 'Remboursement autorisé');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('payment_refunds', 1);
    }

    public function test_paid_cancellation_requires_refund_permission_in_addition_to_order_cancel_permission(): void
    {
        [$owner, $organization, $store, $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '100.0000');
        $salesAdmin = User::factory()->create();
        $this->addOrganizationMember($organization, $salesAdmin, [
            'sales_orders.view', 'sales_orders.cancel',
        ], roleName: 'Cancellation operator');
        $this->addStoreMember($store, $salesAdmin);
        $this->activate($salesAdmin, $organization, $store);

        try {
            app(CancelSalesOrderAction::class)->execute($salesAdmin, $order->fresh(), 'Tentative sans droit financier');
            $this->fail('Paid cancellation must require payments.reverse.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('confirmed', $order->fresh()->status->value);
            $this->assertDatabaseCount('payment_refunds', 0);
        }
    }

    public function test_cross_tenant_order_cannot_be_cancelled_or_refunded(): void
    {
        [$owner, , , $order, $account] = $this->paymentFixture();
        $this->recordPayment($owner, $order, $account, '100.0000');
        $attacker = User::factory()->create();
        $attackerOrganization = $this->createOrganization($attacker);
        $attackerStore = $this->createStore($attackerOrganization, $attacker);
        $this->activate($attacker, $attackerOrganization, $attackerStore);

        try {
            app(CancelSalesOrderAction::class)->execute($attacker, $order->fresh(), 'Cross-tenant attack');
            $this->fail('Cross-tenant cancellation must be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('confirmed', $order->fresh()->status->value);
            $this->assertDatabaseCount('payment_refunds', 0);
        }
    }

    public function test_finance_distinguishes_gross_collections_refunds_and_net_collected(): void
    {
        [$owner, $organization, , $order, $account] = $this->paymentFixture('100.1234');
        $this->recordPayment($owner, $order, $account, '40.1234');
        app(CancelSalesOrderAction::class)->execute($owner, $order, 'Annulation avant remise');

        $period = FinancePeriod::fromMonth(now()->format('Y-m'));
        $situation = app(FinanceMonthlyReportService::class)->situation($organization, $period, null);

        $this->assertSame('40.1234', $situation['encaissements']);
        $this->assertSame('40.1234', $situation['remboursements']);
        $this->assertSame('0.0000', $situation['net_encaisse']);
    }
}
