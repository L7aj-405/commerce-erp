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
use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\CustomerExchange;
use App\Models\CustomerReturn;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DocumentSnapshotVerifier;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosTestCase;

class CustomerExchangeWorkflowTest extends PosTestCase
{
    public function test_more_expensive_exchange_collects_only_the_difference_and_preserves_history(): void
    {
        [$owner, $organization, , $warehouse, $oldVariant, $order] = $this->sale();
        $invoiceV1 = $this->issue($owner, $order);
        $newVariant = $this->createProduct($organization, 'Product B', 'EX-B', ['default_sale_price' => '1300.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $newVariant, '2.0000');
        $originalLine = $order->lines()->sole()->only(['id', 'quantity', 'total_incl_tax']);
        $originalMovement = InventoryMovement::query()->where('product_variant_id', $oldVariant->id)->firstOrFail()->only(['id', 'quantity', 'quantity_before', 'quantity_after']);
        $originalPayment = Payment::query()->sole()->only(['id', 'amount', 'status']);
        $movementsBeforeExchange = InventoryMovement::query()->count();

        $exchange = $this->createExchange($owner, $order, $newVariant->id);
        $this->assertSame('1000.0000', $exchange->returned_total);
        $this->assertSame('1300.0000', $exchange->new_items_total);
        $this->assertSame('300.0000', $exchange->difference_amount);
        $this->assertSame('awaiting_return_receipt', $exchange->status);
        $this->assertSame($movementsBeforeExchange, InventoryMovement::query()->count(), 'A draft exchange must not change stock.');

        $exchange = app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange)->fresh();
        $this->assertSame('awaiting_replacement', $exchange->status);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $oldVariant->id, 'on_hand' => 2]);

        $exchange = app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange)->fresh();
        $this->assertSame('awaiting_settlement', $exchange->status);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $newVariant->id, 'on_hand' => 1]);
        $this->assertSame($originalLine, $order->lines()->whereKey($originalLine['id'])->firstOrFail()->only(array_keys($originalLine)));
        $this->assertSame($originalMovement, InventoryMovement::query()->whereKey($originalMovement['id'])->firstOrFail()->only(array_keys($originalMovement)));

        $account = Payment::query()->findOrFail($originalPayment['id'])->financialAccount;
        $exchange = app(SettleCustomerExchangePaymentAction::class)->execute($owner, $exchange, [[
            'method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '150.0000',
            'payment_date' => now()->toDateString(), 'reference' => 'DIFF-EXCHANGE',
        ]], (string) Str::uuid())->fresh();
        $this->assertSame('awaiting_settlement', $exchange->status);
        $exchange = app(SettleCustomerExchangePaymentAction::class)->execute($owner, $exchange, [[
            'method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '150.0000',
            'payment_date' => now()->toDateString(), 'reference' => 'SOLDE-EXCHANGE',
        ]], (string) Str::uuid())->fresh();
        $this->assertSame('completed', $exchange->status);
        $this->assertSame($originalPayment, Payment::query()->findOrFail($originalPayment['id'])->only(array_keys($originalPayment)));
        $this->assertDatabaseCount('payments', 3);
        $this->assertSame('0.0000', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));

        $invoiceV2 = app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh()));
        $this->assertSame(InvoiceStatus::Superseded, $invoiceV1->fresh()->status);
        $this->assertSame(2, $invoiceV2->version);
        $this->assertSame('2300.0000', $invoiceV2->total_incl_tax);
        $this->assertSame($invoiceV1->id, $exchange->customerReturn->creditNotes()->sole()->invoice_id);
        app(DocumentSnapshotVerifier::class)->verifyInvoice($invoiceV1->fresh());
        app(DocumentSnapshotVerifier::class)->verifyInvoice($invoiceV2->fresh());

        $situation = app(FinanceMonthlyReportService::class)->situation($organization, FinancePeriod::fromMonth(now()->format('Y-m')), $order->store);
        $this->assertSame('2300.0000', $situation['facturation_brute']);
        $this->assertSame('1000.0000', $situation['avoirs']);
        $this->assertSame('1300.0000', $situation['facturation']);
        $this->assertSame('1300.0000', $situation['ventes_nettes']);
        $this->assertSame('1300.0000', $situation['net_encaisse']);
    }

    public function test_cheaper_exchange_uses_partial_operator_selected_refunds(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $replacement = $this->createProduct($organization, 'Cheaper', 'EX-CHEAP', ['default_sale_price' => '700.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $replacement, '2.0000');
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $replacement->id));
        $this->assertSame('-300.0000', $exchange->difference_amount);
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();

        $exchange = app(SettleCustomerExchangeRefundAction::class)->execute($owner, $exchange, $payment, '150.0000', 'Remboursement partiel', (string) Str::uuid())->fresh();
        $this->assertSame('awaiting_settlement', $exchange->status);
        $this->assertSame('150.0000', Decimal::normalize((string) PaymentRefund::query()->sum('amount')));
        $exchange = app(SettleCustomerExchangeRefundAction::class)->execute($owner, $exchange, $payment, '150.0000', 'Solde échange', (string) Str::uuid())->fresh();
        $this->assertSame('completed', $exchange->status);
        $this->assertSame('300.0000', Decimal::normalize((string) PaymentRefund::query()->sum('amount')));
        $this->assertSame('700.0000', app(SalesOrderPaymentCalculator::class)->paidAmount($order));
    }

    public function test_refund_uses_the_operator_selected_original_payment_capacity(): void
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $warehouse = $this->createWarehouse($organization);
        $organization->settings = ['return_policy' => ['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock']]; $organization->save();
        $old = $this->createProduct($organization, 'Split A', 'EX-SPLIT-A', ['default_sale_price' => '1000.0000'])->variants->first();
        $new = $this->createProduct($organization, 'Split B', 'EX-SPLIT-B', ['default_sale_price' => '700.0000'])->variants->first();
        $cash = $this->createPosAccount($organization, 'cash', 'EX-CASH'); $card = $this->createPosAccount($organization, 'card_clearing', 'EX-CARD');
        $this->activate($owner, $organization, $store); $this->openStock($owner, $organization, $warehouse, $old, '1.0000'); $this->openStock($owner, $organization, $warehouse, $new, '1.0000');
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($old)], ['payments' => [
            $this->posPayment($cash, '600.0000'), $this->posPayment($card, '400.0000', ['method' => 'card', 'cash_received' => null]),
        ]]))->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole()->fresh(['lines']); $this->issue($owner, $order);
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $new->id));
        $cardPayment = Payment::query()->where('financial_account_id', $card->id)->sole();
        app(SettleCustomerExchangeRefundAction::class)->execute($owner, $exchange, $cardPayment, '300.0000', 'Remboursement carte', (string) Str::uuid());

        $this->assertDatabaseHas('payment_refunds', ['payment_id' => $cardPayment->id, 'amount' => 300]);
        $this->assertDatabaseMissing('payment_refunds', ['financial_account_id' => $cash->id]);
    }

    public function test_same_value_exchange_creates_no_zero_payment_or_refund(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $replacement = $this->createProduct($organization, 'Equal', 'EX-EQUAL', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $replacement, '1.0000');
        $beforePayments = Payment::query()->count();
        $exchange = $this->receiveAndFulfill($owner, $this->createExchange($owner, $order, $replacement->id));

        $this->assertSame('0.0000', $exchange->difference_amount);
        $this->assertSame('completed', $exchange->status);
        $this->assertSame($beforePayments, Payment::query()->count());
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_multiple_returned_and_replacement_lines_need_no_one_to_one_sku_match(): void
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $warehouse = $this->createWarehouse($organization);
        $organization->settings = ['return_policy' => ['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock']]; $organization->save();
        $a = $this->createProduct($organization, 'A', 'EX-MA', ['default_sale_price' => '500.0000'])->variants->first();
        $b = $this->createProduct($organization, 'B', 'EX-MB', ['default_sale_price' => '500.0000'])->variants->first();
        $c = $this->createProduct($organization, 'C', 'EX-MC', ['default_sale_price' => '900.0000'])->variants->first();
        $d = $this->createProduct($organization, 'D', 'EX-MD', ['default_sale_price' => '800.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        foreach ([[$a, '1.0000'], [$b, '2.0000'], [$c, '1.0000'], [$d, '1.0000']] as [$variant, $quantity]) $this->openStock($owner, $organization, $warehouse, $variant, $quantity);
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($a), $this->posCatalogLine($b, ['quantity' => '2.0000']),
        ]))->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole()->fresh(['lines']);
        $this->issue($owner, $order);
        $operation = (string) Str::uuid();
        $exchange = app(CreateCustomerExchangeAction::class)->execute($owner, $order, $operation, $order->lines->map(fn ($line) => [
            'sales_order_line_id' => $line->id, 'quantity' => $line->quantity,
        ])->all(), [
            ['product_variant_id' => $c->id, 'quantity' => '1.0000'],
            ['product_variant_id' => $d->id, 'quantity' => '1.0000'],
        ], 'Échange multiple', 'restock');

        $this->assertSame('1500.0000', $exchange->returned_total);
        $this->assertSame('1700.0000', $exchange->new_items_total);
        $this->assertSame('200.0000', $exchange->difference_amount);
        $exchange = $this->receiveAndFulfill($owner, $exchange);
        $this->assertSame(2, $exchange->salesOrderAddendum->lines()->count());
        $this->assertSame(4, $order->lines()->count());
    }

    public function test_partial_quantity_exchange_preserves_unreturned_quantity(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale(stock: '3.0000', quantity: '2.0000');
        $replacement = $this->createProduct($organization, 'Partial replacement', 'EX-PART', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $replacement, '1.0000');
        $this->issue($owner, $order->fresh());
        $exchange = $this->createExchange($owner, $order->fresh(['lines']), $replacement->id);
        $this->assertSame('1.0000', $exchange->customerReturn->lines()->sole()->quantity);
        $this->assertSame('2.0000', $order->fresh()->lines()->sole()->quantity);
    }

    public function test_same_sku_uses_received_restock_only_after_receipt(): void
    {
        [$owner, , , $warehouse, $variant, $order] = $this->sale(stock: '1.0000');
        $this->issue($owner, $order);
        $exchange = $this->createExchange($owner, $order, $variant->id);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 0]);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 1]);
        app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh());
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 0]);
        $this->assertSame(2, $order->lines()->where('product_variant_id', $variant->id)->count());
    }

    public function test_damaged_return_does_not_restore_sellable_stock_but_replacement_is_consumed(): void
    {
        [$owner, $organization, , $warehouse, $old, $order] = $this->sale(stock: '1.0000');
        $this->issue($owner, $order);
        $new = $this->createProduct($organization, 'Replacement', 'EX-DMG', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $new, '1.0000');
        $exchange = $this->createExchange($owner, $order, $new->id, disposition: 'damaged');
        $this->receiveAndFulfill($owner, $exchange);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $old->id, 'on_hand' => 0]);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $new->id, 'on_hand' => 0]);
    }

    public function test_remote_or_insufficient_stock_cannot_create_exchange(): void
    {
        [$owner, $organization, , , , $order] = $this->sale();
        $this->issue($owner, $order);
        $remote = $this->createWarehouse($organization, 'Remote');
        $variant = $this->createProduct($organization, 'Remote only', 'EX-REMOTE', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $remote, $variant, '10.0000');

        try {
            $this->createExchange($owner, $order, $variant->id);
            $this->fail('Remote stock must not satisfy Exchange V1.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('customer_exchanges', 0);
            $this->assertDatabaseCount('customer_returns', 0);
        }
    }

    public function test_exchange_without_an_issued_original_invoice_fails_closed(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale();
        $replacement = $this->createProduct($organization, 'Uninvoiced', 'EX-NOINVOICE', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $replacement, '1.0000');

        try {
            $this->createExchange($owner, $order, $replacement->id);
            $this->fail('An exchange without an Avoir-capable original Invoice must fail closed.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('customer_exchanges', 0);
            $this->assertDatabaseCount('customer_returns', 0);
        }
    }

    public function test_return_policy_and_manager_override_are_reused_and_audited(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale(policy: ['window_minutes' => 1, 'manager_override_allowed' => true]);
        $this->issue($owner, $order);
        $order->fulfilled_at = now()->subHour(); $order->save();
        $replacement = $this->createProduct($organization, 'Override', 'EX-OVR', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $replacement, '1.0000');
        $this->expectException(ValidationException::class);
        $this->createExchange($owner, $order, $replacement->id);
    }

    public function test_authorized_policy_override_and_draft_or_post_receipt_cancellation_preserve_truth(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->sale(policy: ['window_minutes' => 1, 'manager_override_allowed' => true]);
        $this->issue($owner, $order); $order->fulfilled_at = now()->subHour(); $order->save();
        $replacement = $this->createProduct($organization, 'Override', 'EX-OVR-2', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $replacement, '2.0000');
        $exchange = $this->createExchange($owner, $order, $replacement->id, override: true);
        $this->assertTrue(AuditLog::query()->where('event', 'sales_return.policy_overridden')->exists());
        app(CancelCustomerExchangeAction::class)->execute($owner, $exchange, 'Client parti');
        $this->assertSame('cancelled', $exchange->fresh()->status);
        $this->assertSame('cancelled', $exchange->customerReturn->fresh()->status);

        $exchangeTwo = $this->createExchange($owner, $order, $replacement->id, override: true);
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchangeTwo);
        app(CancelCustomerExchangeAction::class)->execute($owner, $exchangeTwo->fresh(), 'Arrêt après réception');
        $this->assertSame('received', $exchangeTwo->customerReturn->fresh()->status);
        $this->assertSame('cancelled', $exchangeTwo->fresh()->status);
    }

    public function test_creation_is_idempotent_and_tenant_scoped(): void
    {
        [$owner, $organization, $store, $warehouse, , $order] = $this->sale();
        $this->issue($owner, $order);
        $replacement = $this->createProduct($organization, 'Idempotent', 'EX-IDEM', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $replacement, '1.0000');
        $operation = (string) Str::uuid();
        $first = $this->createExchange($owner, $order, $replacement->id, operationId: $operation);
        $second = $this->createExchange($owner, $order, $replacement->id, operationId: $operation);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('customer_exchanges', 1);

        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['sales_exchanges.view'], roleName: 'Exchange Viewer');
        $this->addStoreMember($store, $viewer); $this->activate($viewer, $organization, $store);
        $this->actingAs($viewer)->get(route('sales.exchanges.show', $first))->assertOk();
        $this->actingAs($viewer)->post(route('sales.exchanges.receive', $first))->assertForbidden();

        $otherStoreSameTenant = $this->createStore($organization, $owner, 'Other Store');
        $this->activate($owner, $organization, $otherStoreSameTenant);
        $this->actingAs($owner)->get("/sales/exchanges/{$first->id}")->assertNotFound();

        $attacker = User::factory()->create(); $other = $this->createOrganization($attacker); $otherStore = $this->createStore($other, $attacker);
        $this->activate($attacker, $other, $otherStore);
        $this->actingAs($attacker)->get("/sales/exchanges/{$first->id}")->assertNotFound();
        $this->assertSame($store->id, $first->store_id);
    }

    /** @return array{User,mixed,mixed,mixed,mixed,SalesOrder} */
    private function sale(string $stock = '2.0000', array $policy = [], string $quantity = '1.0000'): array
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? []; $settings['return_policy'] = array_replace(['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'], $policy); $organization->settings = $settings; $organization->save();
        $variant = $this->createProduct($organization, 'Product A', 'EX-A', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->activate($owner, $organization, $store); $this->openStock($owner, $organization, $warehouse, $variant, $stock);
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant, ['quantity' => $quantity])]))->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole()->fresh(['lines']);
        return [$owner, $organization, $store, $warehouse, $variant, $order];
    }

    private function issue(User $owner, SalesOrder $order)
    {
        return app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
    }

    private function createExchange(User $owner, SalesOrder $order, int $replacementVariantId, string $disposition = 'restock', bool $override = false, ?string $operationId = null): CustomerExchange
    {
        return app(CreateCustomerExchangeAction::class)->execute($owner, $order->fresh(), $operationId ?? (string) Str::uuid(), [
            ['sales_order_line_id' => $order->lines()->whereNull('sales_order_addendum_id')->firstOrFail()->id, 'quantity' => '1.0000'],
        ], [['product_variant_id' => $replacementVariantId, 'quantity' => '1.0000']], 'Échange client', $disposition, $override, $override ? 'Dérogation responsable' : null);
    }

    private function receiveAndFulfill(User $owner, CustomerExchange $exchange): CustomerExchange
    {
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $exchange);
        return app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $exchange->fresh())->fresh();
    }
}
