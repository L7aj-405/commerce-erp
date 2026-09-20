<?php

namespace Tests\Feature\Returns;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Actions\Returns\RefundCustomerReturnAction;
use App\Actions\Pos\AddItemsToCompletedPosOrderAction;
use App\Contracts\PdfGenerator;
use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ReturnPolicyService;
use App\Services\CreditNoteDocumentRenderer;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReceivablesService;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\Export\FinanceCaEncaisseFullPackageExport;
use App\Services\Finance\Export\FinanceCaEncaisseInvoiceZipExport;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use Tests\Support\PosTestCase;
use ZipArchive;

class CustomerReturnWorkflowTest extends PosTestCase
{
    public function test_returns_disabled_and_unconfigured_override_fail_closed(): void
    {
        [$owner, $order] = $this->fulfilledOrder(['enabled' => false]);
        $this->expectException(ValidationException::class);
        app(CreateCustomerReturnAction::class)->execute($owner, $order, [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => '1']], 'Retour', 'restock', (string) Str::uuid(), true, 'Tentative');
    }

    public function test_deadline_boundary_is_inclusive_and_expired_cashier_is_blocked(): void
    {
        [$owner, $order] = $this->fulfilledOrder(['window_value' => 24, 'window_unit' => 'hours', 'window_minutes' => 1440]);
        $order->fulfilled_at = CarbonImmutable::parse('2026-09-19 15:00:00'); $order->save();
        $atDeadline = app(ReturnPolicyService::class)->evaluate($order->fresh(), CarbonImmutable::parse('2026-09-20 15:00:00'));
        $this->assertTrue($atDeadline['within_policy']);
        $order->fulfilled_at = now()->subHours(25); $order->save();
        $this->expectException(ValidationException::class);
        app(CreateCustomerReturnAction::class)->execute($owner, $order->fresh(), [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => '1']], 'Retour', 'restock', (string) Str::uuid());
    }

    public function test_manager_override_requires_configuration_permission_reason_and_is_audited(): void
    {
        [$owner, $order] = $this->fulfilledOrder(['window_minutes' => 60, 'manager_override_allowed' => true]);
        $order->fulfilled_at = now()->subHours(2); $order->save();
        $return = app(CreateCustomerReturnAction::class)->execute($owner, $order->fresh(), [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => '1']], 'Client insatisfait', 'restock', (string) Str::uuid(), true, 'Autorisation responsable');
        $this->assertTrue($return->policy_snapshot['override_used']);
        $this->assertSame('Autorisation responsable', $return->policy_snapshot['override_reason']);
        $this->assertTrue(AuditLog::query()->where('event', 'sales_return.policy_overridden')->where('auditable_id', $return->id)->exists());
    }

    public function test_draft_has_no_stock_effect_and_receipt_creates_one_positive_immutable_movement(): void
    {
        [$owner, $order, $balance] = $this->fulfilledOrder();
        $before = $balance->fresh()->on_hand;
        $return = $this->createReturn($owner, $order, '1.0000');
        $this->assertSame($before, $balance->fresh()->on_hand);
        $this->assertFalse(InventoryMovement::query()->where('reference', $return->return_number)->exists());
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $this->assertSame('1.0000', InventoryMovement::query()->where('reference', $return->return_number)->sole()->quantity);
        $this->assertSame('1.0000', (string) $balance->fresh()->on_hand);
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return->fresh());
        $this->assertSame(1, InventoryMovement::query()->where('reference', $return->return_number)->count());
    }

    public function test_partial_return_preserves_historical_price_and_allocates_discount_proportionally(): void
    {
        [$owner, $order] = $this->fulfilledOrder([], ['discount_type' => 'fixed', 'discount_value' => '20.0000']);
        $source = $order->lines()->sole();
        $return = $this->createReturn($owner, $order, '1.0000');
        $line = $return->lines()->sole();
        $this->assertSame($source->unit_price_incl_tax, $line->unit_price_incl_tax);
        $this->assertSame(Decimal::divide($source->discount_amount, '2.0000'), $line->discount_amount);
        $this->assertSame(Decimal::divide($source->total_incl_tax, '2.0000'), $line->total_incl_tax);
    }

    public function test_damaged_receipt_never_reintroduces_sellable_stock(): void
    {
        [$owner, $order, $balance] = $this->fulfilledOrder();
        $return = app(CreateCustomerReturnAction::class)->execute($owner, $order, [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => '1']], 'Endommagé', 'damaged', (string) Str::uuid());
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $this->assertSame('0.0000', (string) $balance->fresh()->on_hand);
        $this->assertFalse(InventoryMovement::query()->where('reference', $return->return_number)->exists());
    }

    public function test_full_return_preserves_original_fulfilled_order_history(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        $originalQuantity = $order->lines()->sole()->quantity;
        $return = $this->createReturn($owner, $order, '2.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $this->assertSame('received', $return->fresh()->status);
        $this->assertSame($originalQuantity, $order->fresh()->lines()->sole()->quantity);
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status->value);
    }

    public function test_issued_invoice_is_immutable_and_received_partial_return_issues_separately_numbered_credit_note(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        $invoice = app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $originalTotal = $invoice->total_incl_tax;
        $return = $this->createReturn($owner, $order, '1.0000');
        $draft = CreditNote::query()->where('customer_return_id', $return->id)->sole();
        $this->assertSame('draft', $draft->status);
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $note = $draft->fresh();
        $this->assertSame('issued', $note->status);
        $this->assertStringStartsWith('AV-', $note->credit_note_number);
        $this->assertSame($originalTotal, $invoice->fresh()->total_incl_tax);
        $this->assertSame('issued', $invoice->fresh()->status->value);
    }

    public function test_over_return_and_cross_tenant_access_are_blocked(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        $this->createReturn($owner, $order, '1.5000');
        try {
            $this->createReturn($owner, $order, '1.0000');
            $this->fail('Over-return should fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('customer_returns', 1);
        }
        $attacker = User::factory()->create(); $other = $this->createOrganization($attacker); $otherStore = $this->createStore($other, $attacker); $this->activate($attacker, $other, $otherStore);
        $return = CustomerReturn::query()->firstOrFail();
        $this->actingAs($attacker)->get("/sales/returns/{$return->id}")->assertNotFound();
    }

    public function test_fulfilled_pos_addendum_lines_are_returnable_by_exact_line_identity(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        $variant = $this->createProduct($order->organization, 'Addendum Product', 'ADD-RETURN', ['default_sale_price' => '75.0000'])->variants->first();
        $this->openStock($owner, $order->organization, $order->posWarehouse, $variant, '2.0000');
        app(AddItemsToCompletedPosOrderAction::class)->execute($owner, $order->fresh(), (string) Str::uuid(), [['product_variant_id' => $variant->id, 'quantity' => '1.0000']]);
        $addendumLine = $order->fresh()->lines()->whereNotNull('sales_order_addendum_id')->sole();
        $return = app(CreateCustomerReturnAction::class)->execute($owner, $order->fresh(), [['sales_order_line_id' => $addendumLine->id, 'quantity' => '1']], 'Retour complément', 'restock', (string) Str::uuid());
        $this->assertSame($addendumLine->id, $return->lines()->sole()->sales_order_line_id);
    }

    public function test_return_refund_is_partial_bounded_and_separate_from_credit_note(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000'); app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->firstOrFail();
        $operationId = (string) Str::uuid();
        $refund = app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '50.0000', 'Remboursement partiel', $operationId);
        $replayed = app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '50.0000', 'Remboursement partiel', $operationId);
        $this->assertSame('50.0000', $refund->amount);
        $this->assertSame($refund->id, $replayed->id);
        $this->assertSame(1, $return->refunds()->count());
        $this->assertSame($return->id, $refund->customer_return_id);
        $this->assertSame('issued', $return->creditNotes()->sole()->status);
        $this->assertSame('50.0000', app(FinanceReceivablesService::class)->refundObligationAsOf($order->organization, now(), $order->store));
        $second = app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '50.0000', 'Solde', (string) Str::uuid());
        $this->assertSame('50.0000', $second->amount);
        $this->assertSame(2, $return->refunds()->count());
        $this->assertSame('0.0000', app(FinanceReceivablesService::class)->refundObligationAsOf($order->organization, now(), $order->store));
        $this->expectException(ValidationException::class);
        app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '1.0000', 'Dépassement', (string) Str::uuid());
    }

    public function test_refund_obligation_uses_net_customer_position_and_never_becomes_negative(): void
    {
        // Fully collected and fully credited: 200 - 150 refunded = 50 owed.
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '2.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();
        app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '150.0000', 'Remboursement partiel', (string) Str::uuid());

        $receivables = app(FinanceReceivablesService::class);
        $this->assertSame('50.0000', $receivables->refundObligationAsOf($order->organization, now(), $order->store));

        app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '50.0000', 'Solde', (string) Str::uuid());
        $this->assertSame('0.0000', $receivables->refundObligationAsOf($order->organization, now(), $order->store));
    }

    public function test_refund_obligation_is_bounded_by_actual_over_collection(): void
    {
        // Invoice 1,000; paid 600; Avoir 400 => net invoice 600, no obligation.
        [$ownerA, $orderA] = $this->fulfilledOrder([], ['quantity' => '10.0000'], '600.0000');
        app(IssueInvoiceAction::class)->execute($ownerA, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($ownerA, $orderA));
        $returnA = $this->createReturn($ownerA, $orderA, '4.0000');
        app(ReceiveCustomerReturnAction::class)->execute($ownerA, $returnA);
        $receivables = app(FinanceReceivablesService::class);
        $this->assertSame('0.0000', $receivables->totalAsOf($orderA->organization, now(), $orderA->store));
        $this->assertSame('0.0000', $receivables->refundObligationAsOf($orderA->organization, now(), $orderA->store));

        // Invoice 1,000; paid 800; Avoir 400 => 200 over-collected.
        [$ownerB, $orderB] = $this->fulfilledOrder([], ['quantity' => '10.0000'], '800.0000');
        app(IssueInvoiceAction::class)->execute($ownerB, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($ownerB, $orderB));
        $returnB = $this->createReturn($ownerB, $orderB, '4.0000');
        app(ReceiveCustomerReturnAction::class)->execute($ownerB, $returnB);
        $this->assertSame('200.0000', $receivables->refundObligationAsOf($orderB->organization, now(), $orderB->store));
        $paymentB = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $orderB->id))->sole();
        app(RefundCustomerReturnAction::class)->execute($ownerB, $returnB->fresh(), $paymentB, '150.0000', 'Remboursement partiel', (string) Str::uuid());
        $this->assertSame('50.0000', $receivables->refundObligationAsOf($orderB->organization, now(), $orderB->store));

        // Invoice 1,000; paid 1,000; Avoir 400 => all 400 remains refundable.
        [$ownerC, $orderC] = $this->fulfilledOrder([], ['quantity' => '10.0000']);
        app(IssueInvoiceAction::class)->execute($ownerC, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($ownerC, $orderC));
        $returnC = $this->createReturn($ownerC, $orderC, '4.0000');
        app(ReceiveCustomerReturnAction::class)->execute($ownerC, $returnC);
        $this->assertSame('400.0000', $receivables->refundObligationAsOf($orderC->organization, now(), $orderC->store));
    }

    public function test_refund_obligation_excludes_drafts_and_future_refunds_from_historical_as_of_dates(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        try {
            [$owner, $order] = $this->fulfilledOrder();
            app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
            $return = $this->createReturn($owner, $order, '1.0000');
            $receivables = app(FinanceReceivablesService::class);

            // The Return created a draft Avoir, which has no accounting effect.
            $this->assertSame('0.0000', $receivables->refundObligationAsOf($order->organization, Carbon::parse('2026-09-05'), $order->store));

            Carbon::setTestNow('2026-09-10 10:00:00');
            app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
            $this->assertSame('100.0000', $receivables->refundObligationAsOf($order->organization, Carbon::parse('2026-09-15'), $order->store));

            Carbon::setTestNow('2026-09-20 10:00:00');
            $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();
            app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '50.0000', 'Remboursement futur', (string) Str::uuid());

            $this->assertSame('100.0000', $receivables->refundObligationAsOf($order->organization, Carbon::parse('2026-09-15'), $order->store));
            $this->assertSame('50.0000', $receivables->refundObligationAsOf($order->organization, Carbon::parse('2026-09-20'), $order->store));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_multiple_avoirs_and_refunds_aggregate_once_and_remain_tenant_scoped(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();

        $first = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $first);
        app(RefundCustomerReturnAction::class)->execute($owner, $first->fresh(), $payment, '100.0000', 'Premier Avoir', (string) Str::uuid());

        $second = $this->createReturn($owner, $order, '0.5000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $second);
        app(RefundCustomerReturnAction::class)->execute($owner, $second->fresh(), $payment, '20.0000', 'Deuxième Avoir', (string) Str::uuid());

        $receivables = app(FinanceReceivablesService::class);
        $this->assertSame('30.0000', $receivables->refundObligationAsOf($order->organization, now(), $order->store));

        // An unrelated tenant's fully credited, unrefunded order must not
        // change this organization's or store's obligation.
        [$otherOwner, $otherOrder] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($otherOwner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($otherOwner, $otherOrder));
        $otherReturn = $this->createReturn($otherOwner, $otherOrder, '2.0000');
        app(ReceiveCustomerReturnAction::class)->execute($otherOwner, $otherReturn);

        $this->assertSame('30.0000', $receivables->refundObligationAsOf($order->organization, now(), $order->store));
        $this->assertSame('200.0000', $receivables->refundObligationAsOf($otherOrder->organization, now(), $otherOrder->store));
    }

    public function test_return_show_accounts_for_refunds_from_this_and_previous_returns_without_throwing(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();

        $firstReturn = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $firstReturn);
        app(RefundCustomerReturnAction::class)->execute($owner, $firstReturn->fresh(), $payment, '40.0000', 'Premier remboursement', (string) Str::uuid());

        $secondReturn = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $secondReturn);

        $this->actingAs($owner)->get(route('sales.returns.show', $firstReturn))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Returns/Show')
            ->where('refundSummary.refunded', '40.0000')
            ->where('refundSummary.remaining', '60.0000')
            ->has('payments', 1)
            ->where('payments.0.id', $payment->id)
            ->where('payments.0.refundable_amount', '160.0000')
            ->where('payments.0.suggested_amount', '60.0000'));

        $this->actingAs($owner)->get(route('sales.returns.show', $secondReturn))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('refundSummary.refunded', '0.0000')
            ->where('refundSummary.remaining', '100.0000')
            ->where('payments.0.id', $payment->id)
            ->where('payments.0.refundable_amount', '160.0000')
            ->where('payments.0.suggested_amount', '100.0000'));
    }

    public function test_return_show_keeps_multiple_original_payment_capacities_separate(): void
    {
        [$owner, $order] = $this->fulfilledOrderWithSplitPayments();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $cash = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->where('method', 'cash')->sole();
        $card = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->where('method', 'card')->sole();
        $firstReturn = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $firstReturn);
        app(RefundCustomerReturnAction::class)->execute($owner, $firstReturn->fresh(), $cash, '70.0000', 'Partiel espèces', (string) Str::uuid());
        $secondReturn = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $secondReturn);

        $this->actingAs($owner)->get(route('sales.returns.show', $secondReturn))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('payments', 2)
            ->where('payments.0.id', $cash->id)
            ->where('payments.0.refundable_amount', '130.0000')
            ->where('payments.0.suggested_amount', '100.0000')
            ->where('payments.1.id', $card->id)
            ->where('payments.1.refundable_amount', '100.0000')
            ->where('payments.1.suggested_amount', '100.0000'));
    }

    public function test_return_show_excludes_refunds_from_an_unrelated_sales_order(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();

        $otherVariant = $this->createProduct($order->organization, 'Other Returned Product', 'RET-OTHER', ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($owner, $order->organization, $order->posWarehouse, $otherVariant, '1.0000');
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($order->posWarehouse, [$this->posCatalogLine($otherVariant)]))->assertRedirect();
        $otherOrder = SalesOrder::query()->where('id', '!=', $order->id)->latest('id')->firstOrFail();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $otherOrder));
        $otherReturn = $this->createReturn($owner, $otherOrder, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $otherReturn);
        $otherPayment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $otherOrder->id))->sole();
        app(RefundCustomerReturnAction::class)->execute($owner, $otherReturn->fresh(), $otherPayment, '50.0000', 'Autre commande', (string) Str::uuid());

        $this->actingAs($owner)->get(route('sales.returns.show', $return))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('payments', 1)
            ->where('payments.0.id', $payment->id)
            ->where('payments.0.refundable_amount', '200.0000')
            ->where('payments.0.suggested_amount', '100.0000'));
    }

    public function test_return_show_never_exposes_negative_remaining_or_payment_capacity(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();

        $legacyOverRefund = new PaymentRefund;
        $legacyOverRefund->organization_id = $order->organization_id;
        $legacyOverRefund->store_id = $order->store_id;
        $legacyOverRefund->payment_id = $payment->id;
        $legacyOverRefund->sales_order_id = $order->id;
        $legacyOverRefund->customer_return_id = $return->id;
        $legacyOverRefund->client_operation_id = (string) Str::uuid();
        $legacyOverRefund->financial_account_id = $payment->financial_account_id;
        $legacyOverRefund->refund_number = 'REF-LEGACY-OVER';
        $legacyOverRefund->method = $payment->method;
        $legacyOverRefund->status = 'posted';
        $legacyOverRefund->amount = '250.0000';
        $legacyOverRefund->currency_code = $payment->currency_code;
        $legacyOverRefund->refund_date = now()->toDateString();
        $legacyOverRefund->reason = 'Donnée historique incohérente';
        $legacyOverRefund->refunded_by_user_id = $owner->id;
        $legacyOverRefund->save();

        $this->actingAs($owner)->get(route('sales.returns.show', $return))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('refundSummary.remaining', '0.0000')
            ->has('payments', 0));
    }

    public function test_repeated_partial_returns_absorb_the_exact_historical_residual(): void
    {
        [$owner, $order] = $this->fulfilledOrder([], ['quantity' => '3.0000', 'discount_type' => 'fixed', 'discount_value' => '10.0000']);
        $source = $order->lines()->sole();

        $this->createReturn($owner, $order, '1.0000');
        $this->createReturn($owner, $order, '1.0000');
        $this->createReturn($owner, $order, '1.0000');

        $lines = CustomerReturnLine::query()->where('sales_order_line_id', $source->id)->get();
        foreach (['subtotal_excl_tax', 'discount_amount', 'taxable_amount', 'tax_amount', 'total_incl_tax'] as $field) {
            $sum = $lines->reduce(fn (string $carry, $line) => Decimal::add($carry, $line->{$field}), '0.0000');
            $this->assertSame($source->{$field}, $sum, "{$field} must reconcile exactly after the final partial return.");
        }
    }

    public function test_receipt_fails_closed_if_the_target_invoice_version_was_superseded(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        $invoice = app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        $invoice->status = InvoiceStatus::Superseded;
        $invoice->save();

        try {
            app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
            $this->fail('A Credit Note must not be issued against a superseded Invoice version.');
        } catch (ValidationException) {
            $this->assertSame('draft', $return->fresh()->status);
            $this->assertSame('draft', $return->creditNotes()->sole()->status);
        }
    }

    public function test_issued_credit_note_and_lines_are_immutable(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $note = $return->creditNotes()->sole();

        $note->total_incl_tax = '1.0000';
        $this->expectException(LogicException::class);
        $note->save();
    }

    public function test_issued_credit_note_line_cannot_be_changed(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $line = $return->creditNotes()->sole()->lines()->sole();

        $line->quantity = '0.5000';
        $this->expectException(LogicException::class);
        $line->save();
    }

    public function test_issued_credit_note_source_snapshot_cannot_be_changed(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $source = $return->lines()->sole();

        $source->sku = 'ALTERED-SNAPSHOT';
        $this->expectException(LogicException::class);
        $source->save();
    }

    public function test_credit_note_pdf_uses_historical_snapshots_and_explicit_tax_detail(): void
    {
        [$owner, $order] = $this->fulfilledOrder([], ['discount_type' => 'fixed', 'discount_value' => '10.0000']);
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $source = $order->lines()->sole();
        $originalName = $source->product_name;
        $originalSku = $source->sku;
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $note = $return->creditNotes()->sole();

        $source->product_name = 'Nom produit modifié après émission';
        $source->sku = 'SKU-MODIFIE';
        $source->save();
        $html = app(CreditNoteDocumentRenderer::class)->html($note->fresh());

        $this->assertStringContainsString($originalName, $html);
        $this->assertStringContainsString($originalSku, $html);
        $this->assertStringNotContainsString('SKU-MODIFIE', $html);
        $this->assertStringContainsString('Total HT net', $html);
        $this->assertStringContainsString('Base HT', $html);
        $this->assertStringContainsString('ne constitue pas une preuve de remboursement', $html);
    }

    public function test_draft_credit_note_is_excluded_and_issued_credit_note_is_counted_once(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));
        $report = app(FinanceMonthlyReportService::class);

        $this->assertSame('0.0000', $report->creditNotesTotal($order->organization, $period, $order->store));
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $this->assertSame($return->creditNotes()->sole()->total_incl_tax, $report->creditNotesTotal($order->organization, $period, $order->store));
    }

    public function test_partial_return_preserves_gross_history_and_exposes_net_sale_everywhere(): void
    {
        [$owner, $order] = $this->fulfilledAccountingExample();
        $invoice = app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));
        $situation = app(FinanceMonthlyReportService::class)->situation($order->organization, $period, $order->store);

        $this->assertSame('6906.0000', $situation['ventes']);
        $this->assertSame('4356.0000', $situation['ventes_nettes']);
        $this->assertSame('6906.0000', $situation['facturation_brute']);
        $this->assertSame('2550.0000', $situation['avoirs']);
        $this->assertSame('4356.0000', $situation['facturation']);
        $this->assertSame('6906.0000', $invoice->fresh()->total_incl_tax);

        $documents = app(FinanceMonthlyReportService::class)->accountingDocumentsCursor($order->organization, $period, $order->store)->collect();
        $this->assertSame(['AVOIR', 'FACTURE'], $documents->pluck('document_type')->sort()->values()->all());
        $this->assertSame('6906.0000', $documents->firstWhere('document_type', 'FACTURE')['net_amount']);
        $this->assertSame('-2550.0000', $documents->firstWhere('document_type', 'AVOIR')['net_amount']);

        $this->actingAs($owner)->get(route('sales.orders.index'))->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.return_state', 'partial')
            ->where('orders.data.0.net_total', '4356.0000'));
        $this->actingAs($owner)->get(route('invoices.index'))->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.credit_state', 'partial')
            ->where('invoices.data.0.net_total_incl_tax', '4356.0000'));
    }

    public function test_full_return_keeps_invoice_history_but_contributes_zero_to_net_sales(): void
    {
        [$owner, $order] = $this->fulfilledAccountingExample();
        $invoice = app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = app(CreateCustomerReturnAction::class)->execute($owner, $order->fresh(), $order->lines->map(fn ($line) => [
            'sales_order_line_id' => $line->id,
            'quantity' => $line->quantity,
        ])->all(), 'Retour intégral', 'restock', (string) Str::uuid());
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));
        $situation = app(FinanceMonthlyReportService::class)->situation($order->organization, $period, $order->store);
        $events = app(FinanceJournalService::class)->events($order->organization, $period, $order->store);

        $this->assertSame('6906.0000', $situation['facturation_brute']);
        $this->assertSame('6906.0000', $situation['avoirs']);
        $this->assertSame('0.0000', $situation['facturation']);
        $this->assertSame('0.0000', $situation['ventes_nettes']);
        $this->assertSame('issued', $invoice->fresh()->status->value);
        $this->assertEqualsCanonicalizing(['invoice', 'credit_note', 'payment'], $events->pluck('type')->all());

        $this->actingAs($owner)->get(route('sales.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('commercialSummary.state', 'full')
            ->where('commercialSummary.fully_returned', true)
            ->where('commercialSummary.net', '0.0000'));
        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
            ->where('creditSummary.state', 'full')
            ->where('creditSummary.net', '0.0000'));
    }

    public function test_invoice_avoir_and_refund_keep_their_own_accounting_periods(): void
    {
        [$owner, $order] = $this->fulfilledOrder(['window_value' => 60, 'window_unit' => 'days', 'window_minutes' => 86400]);
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order, ['invoice_date' => '2026-09-20']));
        $payment = Payment::query()->whereHas('allocations', fn ($query) => $query->where('sales_order_id', $order->id))->sole();

        Carbon::setTestNow('2026-10-05 10:00:00');
        try {
            $return = $this->createReturn($owner, $order, '1.0000');
            app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
            app(RefundCustomerReturnAction::class)->execute($owner, $return->fresh(), $payment, '100.0000', 'Remboursement octobre', (string) Str::uuid());
        } finally {
            Carbon::setTestNow();
        }

        $report = app(FinanceMonthlyReportService::class);
        $september = $report->situation($order->organization, FinancePeriod::fromMonth('2026-09'), $order->store);
        $october = $report->situation($order->organization, FinancePeriod::fromMonth('2026-10'), $order->store);
        $this->assertSame('200.0000', $september['facturation_brute']);
        $this->assertSame('0.0000', $september['avoirs']);
        $this->assertSame('0.0000', $october['facturation_brute']);
        $this->assertSame('100.0000', $october['avoirs']);
        $this->assertSame('100.0000', $october['remboursements']);
    }

    public function test_accountant_zip_contains_unique_invoice_and_credit_note_pdfs(): void
    {
        $generator = Mockery::mock(PdfGenerator::class);
        $generator->shouldReceive('generate')->andReturn('%PDF-1.4 fake');
        $this->app->instance(PdfGenerator::class, $generator);
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);

        $result = app(FinanceCaEncaisseInvoiceZipExport::class)->build($order->organization, FinancePeriod::fromMonth(now()->format('Y-m')), $order->store);
        $zip = new ZipArchive;
        $zip->open($result['path']);
        $pdfNames = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))->filter(fn ($name) => str_ends_with((string) $name, '.pdf'));
        $this->assertCount(2, $pdfNames);
        $this->assertCount(2, $pdfNames->unique());
        $this->assertTrue($pdfNames->contains(fn ($name) => str_contains((string) $name, 'Facture')));
        $this->assertTrue($pdfNames->contains(fn ($name) => str_contains((string) $name, 'Avoir')));
        $this->assertTrue($pdfNames->every(fn ($name) => str_starts_with((string) $name, 'Factures/')));
        $this->assertNotFalse($zip->getFromName('manifest.csv'));
        $this->assertNotFalse($zip->getFromName('avoirs-manifest.csv'));
        $zip->close();
        @unlink($result['path']);
    }

    public function test_accountant_full_package_contains_ca_excel_invoice_excel_invoice_pdf_and_credit_note_pdf(): void
    {
        $generator = Mockery::mock(PdfGenerator::class);
        $generator->shouldReceive('generate')->andReturn('%PDF-1.4 fake');
        $this->app->instance(PdfGenerator::class, $generator);
        [$owner, $order] = $this->fulfilledOrder();
        $invoice = app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $creditNote = $return->fresh('creditNotes')->creditNotes->sole();
        $month = now()->format('Y-m');

        $result = app(FinanceCaEncaisseFullPackageExport::class)->build($order->organization, FinancePeriod::fromMonth($month), $order->store);

        $zip = new ZipArchive;
        $zip->open($result['path']);
        $entries = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))->filter()->values();
        $root = "Package_CA_{$month}";
        $safeInvoice = $this->safeDocumentNumber($invoice->invoice_number);
        $safeCreditNote = $this->safeDocumentNumber($creditNote->credit_note_number);

        $this->assertStringEndsWith("Package_CA_{$month}.zip", $result['filename']);
        $this->assertTrue($entries->contains("{$root}/CA_Encaisse_{$month}.xlsx"));
        $this->assertTrue($entries->contains("{$root}/Factures_PDF/Facture-{$safeInvoice}.pdf"));
        $this->assertTrue($entries->contains("{$root}/Factures_PDF/Avoir-{$safeCreditNote}.pdf"));
        $this->assertTrue($entries->contains("{$root}/Factures_Excel/Facture_{$safeInvoice}.xlsx"));
        $zip->close();
        @unlink($result['path']);
    }

    public function test_credit_note_routes_enforce_permission_and_hide_cross_tenant_documents(): void
    {
        [$owner, $order] = $this->fulfilledOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = $this->createReturn($owner, $order, '1.0000');
        app(ReceiveCustomerReturnAction::class)->execute($owner, $return);
        $note = $return->creditNotes()->sole();

        $sameTenantUser = User::factory()->create();
        $this->addOrganizationMember($order->organization, $sameTenantUser);
        $this->addStoreMember($order->store, $sameTenantUser);
        $this->activate($sameTenantUser, $order->organization, $order->store);

        foreach (['credit-notes.show', 'credit-notes.print', 'credit-notes.pdf'] as $route) {
            $this->actingAs($sameTenantUser)->get(route($route, $note))->assertForbidden();
        }

        $crossTenantUser = User::factory()->create();
        $otherOrganization = $this->createOrganization($crossTenantUser);
        $otherStore = $this->createStore($otherOrganization, $crossTenantUser);
        $this->activate($crossTenantUser, $otherOrganization, $otherStore);

        foreach (['credit-notes.show', 'credit-notes.print', 'credit-notes.pdf'] as $route) {
            $this->actingAs($crossTenantUser)->get(route($route, $note))->assertNotFound();
        }
    }

    private function createReturn(User $owner, SalesOrder $order, string $quantity): CustomerReturn
    {
        return app(CreateCustomerReturnAction::class)->execute($owner, $order->fresh(), [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => $quantity]], 'Retour client', 'restock', (string) Str::uuid());
    }

    private function safeDocumentNumber(string $number): string
    {
        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $number), '-') ?: 'document';
    }

    /** @return array{User,SalesOrder,InventoryBalance} */
    private function fulfilledOrder(array $policy = [], array $lineOverrides = [], ?string $paymentAmount = null): array
    {
        $owner = User::factory()->create(); $organization = $this->createOrganization($owner); $store = $this->createStore($organization, $owner); $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? []; $settings['return_policy'] = array_replace(['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'], $policy); $organization->settings = $settings; $organization->save();
        $variant = $this->createProduct($organization, 'Returned Product', 'RET-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store); $this->openStock($owner, $organization, $warehouse, $variant, (string) ($lineOverrides['quantity'] ?? '2.0000'));
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant, array_replace(['quantity' => '2.0000'], $lineOverrides))]);
        if ($paymentAmount !== null) {
            $payload['payments'][0]['amount'] = $paymentAmount;
            $payload['payments'][0]['cash_received'] = $paymentAmount;
        }
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->where('store_id', $store->id)->sole();
        $balance = InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->firstOrFail();
        return [$owner, $order, $balance];
    }

    /** @return array{User,SalesOrder} */
    private function fulfilledOrderWithSplitPayments(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $organization->settings = $settings;
        $organization->save();
        $variant = $this->createProduct($organization, 'Split Payment Product', 'RET-SPLIT', ['default_sale_price' => '100.0000'])->variants->first();
        $cash = $this->createPosAccount($organization, 'cash', 'RET-CASH');
        $card = $this->createPosAccount($organization, 'card_clearing', 'RET-CARD');
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '3.0000');
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant, ['quantity' => '3.0000'])], ['payments' => [
            $this->posPayment($cash, '200.0000'),
            $this->posPayment($card, '100.0000', ['method' => 'card', 'cash_received' => null]),
        ]]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();

        return [$owner, SalesOrder::query()->sole()];
    }

    /** @return array{User,SalesOrder} */
    private function fulfilledAccountingExample(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $organization->settings = $settings;
        $organization->save();
        $productA = $this->createProduct($organization, 'Product A', 'ACCOUNT-A', ['default_sale_price' => '2550.0000'])->variants->first();
        $productB = $this->createProduct($organization, 'Product B', 'ACCOUNT-B', ['default_sale_price' => '4356.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $productA, '1.0000');
        $this->openStock($owner, $organization, $warehouse, $productB, '1.0000');
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [
            $this->posCatalogLine($productA),
            $this->posCatalogLine($productB),
        ]))->assertRedirect();

        return [$owner, SalesOrder::query()->sole()];
    }
}
