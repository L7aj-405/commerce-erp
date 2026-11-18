<?php

namespace Tests\Feature\Finance;

use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Actions\Returns\RefundCustomerReturnAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Sandbox;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerFinancialSummary;
use App\Services\Finance\FinanceReceivablesService;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentTestCase;

/**
 * F-01 — a customer's issued-invoice balance must be derived ORDER BY ORDER, never as
 * "all invoices minus all payments of the customer".
 *
 * Payments collected on order B (not invoiced) must not reduce the receivable of the issued
 * invoice of order A; credit notes, refunds and reversed payments are honoured per order; an
 * over-collection is reported (refund_obligation), not silently clamped away; Test Mode rows
 * and documents of stores the viewer cannot see never move a figure.
 *
 * The expected values below are the accounting truth, not a description of the old behaviour.
 */
class CustomerBalanceNoCrossOrderNettingTest extends DocumentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    private FinancialAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->customer = $this->createCustomer($this->org, 'Client A', ['company_name' => 'Client A SARL', 'tax_identifier' => 'ICE-A']);
        $this->cash = $this->createFinancialAccount($this->org, ['name' => 'Caisse', 'code' => 'CAISSE']);
        $this->activate($this->owner, $this->org, $this->store);
    }

    // ---- 1. The reported case -------------------------------------------------------------------------

    public function test_advance_on_unbilled_order_b_does_not_reduce_the_balance_of_invoiced_order_a(): void
    {
        $this->invoicedUnpaidOrder('100.0000');
        $orderB = $this->confirmedOrder('60.0000');
        $this->pay($orderB, '60.0000');

        $summary = $this->summary();

        $this->assertSame('100.0000', $summary['outstanding'], 'Invoice A is wholly unpaid: its receivable is 100 whatever was collected on order B.');
        $this->assertSame('0.0000', $summary['unbilled_outstanding'], 'Order B is fully paid: nothing is owed on it.');
        $this->assertSame('0.0000', $summary['refund_obligation']);
    }

    // ---- 2. Partially paid ----------------------------------------------------------------------------

    public function test_partially_paid_invoice_keeps_its_own_remaining_balance_next_to_an_unrelated_advance(): void
    {
        [$orderA] = $this->invoicedUnpaidOrder('100.0000');
        $this->pay($orderA, '30.0000');
        $orderB = $this->confirmedOrder('80.0000');
        $this->pay($orderB, '60.0000');

        $summary = $this->summary();

        $this->assertSame('70.0000', $summary['outstanding']);
        $this->assertSame('20.0000', $summary['unbilled_outstanding']);
    }

    // ---- 3. Reversed payment --------------------------------------------------------------------------

    public function test_a_reversed_payment_restores_exactly_its_own_orders_receivable(): void
    {
        [$orderA] = $this->invoicedUnpaidOrder('100.0000');
        $orderB = $this->confirmedOrder('60.0000');
        $this->pay($orderB, '60.0000');
        $paymentA = $this->pay($orderA, '100.0000');
        $this->assertSame('0.0000', $this->summary()['outstanding']);

        app(ReversePaymentAction::class)->execute($this->owner, $paymentA, 'Erreur de saisie');

        $summary = $this->summary();
        $this->assertSame('100.0000', $summary['outstanding'], 'The reversed payment is history: invoice A owes its full 100 again.');
        $this->assertSame('0.0000', $summary['unbilled_outstanding'], 'Order B (paid 60/60) is untouched by A\'s reversal.');
    }

    // ---- 4 & 5. Partial return + credit note, refund --------------------------------------------------

    public function test_partial_return_credit_note_reduces_only_its_own_invoice(): void
    {
        [$order, $invoice, $variant] = $this->invoicedFulfilledCatalogOrder('2.0000');
        $other = $this->confirmedOrder('50.0000');
        $this->pay($other, '50.0000');

        $this->receiveReturn($order, '1.0000');

        $summary = $this->summary();
        $this->assertSame('100.0000', $summary['outstanding'], 'Invoice 200 − credit note 100, nothing collected on it.');
        $this->assertSame('0.0000', $summary['refund_obligation']);
        $this->assertSame('200.0000', $invoice->fresh()->total_incl_tax, 'The issued invoice itself is immutable.');
    }

    public function test_over_collection_after_a_credit_note_is_reported_not_hidden_and_a_refund_clears_it(): void
    {
        [$order] = $this->invoicedFulfilledCatalogOrder('2.0000');
        $payment = $this->pay($order, '200.0000');
        $this->assertSame('0.0000', $this->summary()['outstanding']);

        $return = $this->receiveReturn($order, '1.0000');
        $afterCreditNote = $this->summary();
        $this->assertSame('0.0000', $afterCreditNote['outstanding'], 'Nothing is owed BY the customer…');
        $this->assertSame('100.0000', $afterCreditNote['refund_obligation'], '…but 100 is owed TO the customer: it must be visible, not clamped away.');

        app(RefundCustomerReturnAction::class)->execute($this->owner, $return->fresh(), $payment->fresh(), '100.0000', 'Remboursement du retour', (string) Str::uuid());

        $afterRefund = $this->summary();
        $this->assertSame('0.0000', $afterRefund['outstanding']);
        $this->assertSame('0.0000', $afterRefund['refund_obligation']);
    }

    // ---- 6. B later invoiced --------------------------------------------------------------------------

    public function test_when_order_b_is_later_invoiced_its_exposure_moves_to_receivables_without_double_counting(): void
    {
        $this->invoicedUnpaidOrder('100.0000');
        $orderB = $this->confirmedOrder('80.0000');
        $this->pay($orderB, '60.0000');
        $before = $this->summary();
        $this->assertSame('100.0000', $before['outstanding']);
        $this->assertSame('20.0000', $before['unbilled_outstanding']);

        $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $orderB->fresh()));

        $after = $this->summary();
        $this->assertSame('120.0000', $after['outstanding'], 'A: 100 + B: 80 − 60 = 120.');
        $this->assertSame('0.0000', $after['unbilled_outstanding'], 'B left the unbilled bucket the moment its invoice was issued.');
    }

    // ---- 7. Two customers -----------------------------------------------------------------------------

    public function test_another_customers_payments_never_move_this_customers_balance(): void
    {
        $this->invoicedUnpaidOrder('100.0000');
        $other = $this->createCustomer($this->org, 'Client B');
        $otherOrder = $this->confirmedOrder('500.0000', $other);
        $this->pay($otherOrder, '500.0000');
        [$otherInvoiceOrder] = $this->invoicedUnpaidOrder('40.0000', $other);

        $this->assertSame('100.0000', $this->summary()['outstanding']);
        $this->assertSame('40.0000', $this->summary($other)['outstanding']);
        $this->assertNotNull($otherInvoiceOrder);
    }

    // ---- 8. Multiple organizations --------------------------------------------------------------------

    public function test_other_organizations_data_never_leaks_into_a_summary(): void
    {
        $this->invoicedUnpaidOrder('100.0000');

        $ownerB = User::factory()->create();
        $orgB = $this->createOrganization($ownerB, 'Org B');
        $storeB = $this->createStore($orgB, $ownerB, 'Store B');
        $customerB = $this->createCustomer($orgB, 'Client B');
        $accountB = $this->createFinancialAccount($orgB, ['name' => 'Caisse B', 'code' => 'CAISSE-B']);
        $this->activate($ownerB, $orgB, $storeB);
        $orderB = $this->createDraftOrder($ownerB, $orgB, $storeB, $customerB, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($ownerB, $orderB, ['unit_price_excl_tax' => '900.0000']);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($ownerB, $orderB)->fresh();
        $this->issueInvoice($ownerB, $this->createInvoice($ownerB, $orderB));
        $this->recordPayment($ownerB, $orderB, $accountB, '300.0000');
        $this->activate($this->owner, $this->org, $this->store);

        $this->assertSame('100.0000', $this->summary()['outstanding']);
        // The service itself refuses to look across organizations, even if asked with a foreign customer id.
        $position = app(FinanceReceivablesService::class)->customerPositionAsOf($this->org, (int) $customerB->getKey(), [(int) $this->store->getKey()], now());
        $this->assertSame('0.0000', $position['receivable']);
        $this->assertSame('0.0000', $position['unbilled']);
    }

    // ---- 9. Store-limited visibility ------------------------------------------------------------------

    public function test_a_store_limited_viewer_only_sums_documents_of_the_stores_they_belong_to(): void
    {
        $this->invoicedUnpaidOrder('100.0000');                       // store 1
        $store2 = $this->createStore($this->org, $this->owner, 'Store 2');
        $this->activate($this->owner, $this->org, $store2);
        $orderS2 = $this->createDraftOrder($this->owner, $this->org, $store2, $this->customer, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($this->owner, $orderS2, ['unit_price_excl_tax' => '70.0000']);
        $orderS2 = app(ConfirmSalesOrderAction::class)->execute($this->owner, $orderS2)->fresh();
        $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $orderS2));   // invoice in store 2, unpaid
        $this->activate($this->owner, $this->org, $this->store);

        $this->assertSame('170.0000', $this->summary()['outstanding'], 'The owner sees both stores.');

        $limited = $this->limitedViewer($this->store);
        $this->assertSame('100.0000', $this->summary($this->customer, $limited)['outstanding'], 'A store-1 user never sees or sums store 2.');
    }

    // ---- 10. Sandbox isolation ------------------------------------------------------------------------

    public function test_test_mode_documents_never_enter_a_production_summary(): void
    {
        [$orderA, $invoiceA] = $this->invoicedUnpaidOrder('100.0000');
        [$testOrder, $testInvoice] = $this->invoicedUnpaidOrder('999.0000');
        $unbilledTest = $this->confirmedOrder('555.0000');

        $sandbox = new Sandbox;
        $sandbox->organization_id = $this->org->getKey();
        $sandbox->name = 'Test';
        $sandbox->status = 'active';
        $sandbox->generation = 1;
        $sandbox->created_by_user_id = $this->owner->getKey();
        $sandbox->save();
        foreach ([[$testOrder, $testInvoice], [$unbilledTest, null]] as [$o, $i]) {
            DB::table('sales_orders')->where('id', $o->getKey())->update(['sandbox_id' => $sandbox->getKey()]);
            if ($i) {
                DB::table('invoices')->where('id', $i->getKey())->update(['sandbox_id' => $sandbox->getKey()]);
            }
        }

        $summary = $this->summary();

        $this->assertSame('100.0000', $summary['outstanding']);
        $this->assertSame('0.0000', $summary['unbilled_outstanding']);
        $this->assertNotNull($orderA);
        $this->assertNotNull($invoiceA);
    }

    // ---- 11. Monetary precision -----------------------------------------------------------------------

    public function test_amounts_are_exact_decimals_never_floats(): void
    {
        [$a] = $this->invoicedUnpaidOrder('0.1000');
        $this->invoicedUnpaidOrder('0.2000');
        $big = $this->invoicedUnpaidOrder('99999999.9900');
        $this->pay($a, '0.0500');
        $this->pay($big[0], '33333333.3300');

        $summary = $this->summary();

        // 0.1000 − 0.0500 + 0.2000 + (99 999 999.9900 − 33 333 333.3300) = 66 666 666.9100 — a float sum would drift.
        $this->assertSame('66666666.9100', $summary['outstanding']);
    }

    public function test_a_sub_centime_residue_is_not_a_receivable_but_a_genuine_centime_is(): void
    {
        [$sub] = $this->invoicedUnpaidOrder('13306.0001');
        $this->pay($sub, '13306.0000');
        $this->assertSame('0.0000', $this->summary()['outstanding'], '0.0001 MAD is not a payable amount (Money::outstanding rule).');
        $this->assertSame('0.0000', $this->summary()['refund_obligation']);

        [$cent] = $this->invoicedUnpaidOrder('50.0100');
        $this->pay($cent, '50.0000');
        $this->assertSame('0.0100', $this->summary()['outstanding'], 'A genuine centime is still owed.');
    }

    public function test_future_dated_documents_are_still_owed_the_profile_has_no_as_of_date(): void
    {
        $future = now()->addDays(10)->toDateString();
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => $future]);
        $this->addCustomLine($this->owner, $order, ['description' => 'Livraison différée', 'unit_price_excl_tax' => '80.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $this->assertSame('80.0000', $this->summary()['unbilled_outstanding'], 'A confirmed order dated in the future is already a commitment.');

        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order, ['invoice_date' => $future]));
        $summary = $this->summary();
        $this->assertSame('80.0000', $summary['outstanding'], 'An invoice dated in the future is a receivable of the profile (it was before F-01).');
        $this->assertSame('0.0000', $summary['unbilled_outstanding']);
        $this->assertNotNull($invoice);
    }

    public function test_customer_balance_is_settlement_exact_while_the_ledger_receivables_stay_exact_to_the_last_decimal(): void
    {
        // Invoice 13306.0001, collected 13306.0000: the order is SETTLED at MAD precision (it could be invoiced),
        // so the customer owes nothing — but the Créances ledger keeps the exact 0.0001 so that the monthly
        // identity "début + facturation − encaissements = fin" holds to the last decimal. Deliberate, documented.
        [$order] = $this->invoicedUnpaidOrder('13306.0001');
        $this->pay($order, '13306.0000');

        $this->assertSame('0.0000', $this->summary()['outstanding']);
        $row = collect(app(FinanceReceivablesService::class)->breakdownAsOf($this->org, now(), null, 50)->items())->firstWhere('sales_order_id', $order->getKey());
        $this->assertSame('0.0001', $row['outstanding'], 'Ledger breakdown keeps the exact residue.');
    }

    // ---- 12. Reconciliation ---------------------------------------------------------------------------

    public function test_summary_reconciles_with_the_receivables_service_and_the_order_calculator(): void
    {
        [$a] = $this->invoicedUnpaidOrder('100.0000');
        [$c] = $this->invoicedUnpaidOrder('45.5000');
        $this->pay($a, '30.0000');
        $this->pay($c, '45.5000');
        $b = $this->confirmedOrder('80.0000');
        $this->pay($b, '10.0000');
        $this->confirmedOrder('25.0000');

        $summary = $this->summary();
        $service = app(FinanceReceivablesService::class);
        $asOf = now();

        // (a) customer receivable == the invoice-based "Créances" total for the whole organization (single customer).
        $this->assertSame($service->totalAsOf($this->org, $asOf, null), $summary['outstanding']);
        // (b) == sum of the per-invoice rows of the "Créances" breakdown.
        $rows = collect($service->breakdownAsOf($this->org, $asOf, null, 100)->items());
        $this->assertSame($summary['outstanding'], $rows->reduce(fn (string $c, array $r) => Decimal::add($c, $r['outstanding']), '0.0000'));
        // (c) R-1: receivable + unbilled exposure == sum of each confirmed order's own remaining balance.
        $calculator = app(SalesOrderPaymentCalculator::class);
        $remaining = SalesOrder::query()->where('organization_id', $this->org->getKey())->where('status', 'confirmed')->get()
            ->reduce(fn (string $c, SalesOrder $o) => Decimal::add($c, $calculator->remainingAmount($o)), '0.0000');
        $this->assertSame($remaining, Decimal::add($summary['outstanding'], $summary['unbilled_outstanding']));
        // (d) the two buckets are disjoint: 100−30 + 45.5−45.5 = 70 receivable ; (80−10) + 25 = 95 unbilled.
        $this->assertSame('70.0000', $summary['outstanding']);
        $this->assertSame('95.0000', $summary['unbilled_outstanding']);
    }

    // ---- permissions keep their null semantics --------------------------------------------------------

    public function test_a_viewer_without_payment_permission_gets_null_never_a_misleading_zero(): void
    {
        $this->invoicedUnpaidOrder('100.0000');
        $viewer = User::factory()->create();
        $role = $this->createRole($this->org, ['customers.view', 'invoices.view', 'sales_orders.view'], 'Sans paiements');
        $membership = new OrganizationMembership;
        $membership->organization_id = $this->org->getKey();
        $membership->user_id = $viewer->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = 'active';
        $membership->save();
        $this->addStoreMember($this->store, $viewer);

        $summary = $this->summary($this->customer, $viewer);

        $this->assertNull($summary['outstanding'], 'Receivable needs invoices.view AND payments.view.');
        $this->assertNull($summary['refund_obligation']);
        $this->assertNull($summary['paid_total']);
        $this->assertNotNull($summary['invoiced_total'], 'invoices.view alone still shows the invoiced total.');
    }

    // ---------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------

    /** @return array<string, ?string> */
    private function summary(?Customer $customer = null, ?User $viewer = null): array
    {
        return app(CustomerFinancialSummary::class)->forCustomer(($customer ?? $this->customer)->fresh(), $viewer ?? $this->owner);
    }

    private function confirmedOrder(string $total, ?Customer $customer = null, ?Store $store = null): SalesOrder
    {
        $store ??= $this->store;
        $order = $this->createDraftOrder($this->owner, $this->org, $store, $customer ?? $this->customer, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($this->owner, $order, ['description' => 'Prestation', 'unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    /** @return array{SalesOrder, Invoice} an order with an ISSUED invoice and nothing (net) collected */
    private function invoicedUnpaidOrder(string $total, ?Customer $customer = null): array
    {
        $order = $this->confirmedOrder($total, $customer);
        // IssueInvoiceAction still requires full payment; the shared helper pays, issues, then reverses its own payment.
        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order));

        return [$order->fresh(), $invoice];
    }

    private function pay(SalesOrder $order, string $amount): Payment
    {
        return $this->recordPayment($this->owner, $order->fresh(), $this->cash, $amount);
    }

    /** @return array{SalesOrder, Invoice, ProductVariant} a fulfilled catalog order (unit price 100) with an issued, unpaid invoice */
    private function invoicedFulfilledCatalogOrder(string $quantity): array
    {
        $settings = $this->org->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080,
            'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $this->org->settings = $settings;
        $this->org->save();
        $warehouse = $this->createWarehouse($this->org);
        $variant = $this->createProduct($this->org, 'Produit retourné', 'F01-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($this->owner, $this->org, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => now()->toDateString()]);
        $this->addCatalogLine($this->owner, $order, $variant, $warehouse, ['quantity' => $quantity]);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $order = app(FulfillSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        // Returns restock into the order's return warehouse, which the POS flow records; set it for this non-POS fixture.
        DB::table('sales_orders')->where('id', $order->getKey())->update(['pos_warehouse_id' => $warehouse->getKey()]);
        $order = $order->fresh();
        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order));

        return [$order->fresh(), $invoice, $variant];
    }

    private function receiveReturn(SalesOrder $order, string $quantity): CustomerReturn
    {
        $return = app(CreateCustomerReturnAction::class)->execute($this->owner, $order->fresh(),
            [['sales_order_line_id' => $order->lines()->first()->getKey(), 'quantity' => $quantity]], 'Retour client', 'restock', (string) Str::uuid());
        app(ReceiveCustomerReturnAction::class)->execute($this->owner, $return);

        return $return->fresh();
    }

    private function limitedViewer(Store $store): User
    {
        $viewer = User::factory()->create();
        $membership = new OrganizationMembership;
        $membership->organization_id = $this->org->getKey();
        $membership->user_id = $viewer->getKey();
        $membership->role_id = $this->org->roles()->where('slug', 'admin')->firstOrFail()->getKey();
        $membership->status = 'active';
        $membership->save();
        $this->addStoreMember($store, $viewer);

        return $viewer;
    }
}
