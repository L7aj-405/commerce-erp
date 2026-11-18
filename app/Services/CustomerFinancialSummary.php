<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Finance\FinanceReceivablesService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Cheap customer financial summary shown on the customer profile (web) and by
 * the MCP balance tool: a handful of scoped aggregate queries, never one per row.
 *
 * W5.1: every figure is computed over the documents the VIEWER may see
 * (DocumentVisibility: view permission + the viewer's active stores), so a
 * document of another store never moves a number. A figure the viewer has no
 * permission for is null (never a partial or a zero that would hint at
 * hidden data): sales/last activity need sales_orders.view, invoiced needs
 * invoices.view, paid needs payments.view, and every figure derived from
 * payments (outstanding, refund_obligation, unbilled_outstanding) needs the
 * view permission of its documents AND payments.view.
 *
 * F-01 — receivables are computed ORDER BY ORDER by FinanceReceivablesService
 * (the same rule as the Finance "Créances" screens), never as
 * "all issued invoices minus all payments of the customer". A payment
 * collected on one order can only ever reduce that order's own balance:
 * an advance on an unbilled order B never reduces the receivable of the
 * issued invoice of order A. Credit notes and refunds are netted per order;
 * an over-collection is reported as `refund_obligation` instead of being
 * clamped away; Test Mode documents are excluded. Like the figures it replaces, the position is current and
 * all-time (no as-of date): a future-dated document is still owed.
 *
 * Field semantics (unchanged unless stated): sales_total = confirmed orders, gross TTC; invoiced_total = issued
 * invoices, GROSS TTC (not net of credit notes); paid_total = GROSS posted payment allocations on the customer's
 * orders, billed or not (not net of refunds); outstanding = NET invoice receivable per order (new definition, F-01);
 * unbilled_outstanding = NET remaining balance of orders without an issued invoice; refund_obligation = NEW.
 */
final class CustomerFinancialSummary
{
    public function __construct(
        private readonly DocumentVisibility $visibility,
        private readonly FinanceReceivablesService $receivables,
    ) {}

    /** @return array{sales_total: ?string, invoiced_total: ?string, paid_total: ?string, outstanding: ?string, refund_obligation: ?string, unbilled_outstanding: ?string, last_activity: ?string} */
    public function forCustomer(Customer $customer, User $viewer): array
    {
        $organization = $customer->organization;
        $customerId = $customer->getKey();
        $orders = $this->visibility->canView($viewer, $organization, SalesOrder::class)
            ? $this->visibility->visible($viewer, $organization, SalesOrder::class)->where('customer_id', $customerId) : null;

        $salesTotal = $orders === null ? null : Decimal::normalize((string) (clone $orders)
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->sum('total_incl_tax'));

        $canSeeInvoices = $this->visibility->canView($viewer, $organization, Invoice::class);
        $canSeePayments = $this->visibility->canView($viewer, $organization, Payment::class);

        $invoicedTotal = ! $canSeeInvoices ? null : Decimal::normalize((string) $this->visibility
            ->visible($viewer, $organization, Invoice::class)
            ->where('customer_id', $customerId)
            ->where('status', InvoiceStatus::Issued->value)
            ->sum('total_incl_tax'));

        $stores = $this->visibility->storeIds($viewer, $organization) ?: [0];
        $paidTotal = ! $canSeePayments ? null : Decimal::normalize((string) DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNull('sales_orders.sandbox_id')->join('sales_orders', 'sales_orders.id', '=', 'payment_allocations.sales_order_id')
            ->where('sales_orders.organization_id', $organization->getKey())
            ->where('sales_orders.customer_id', $customerId)
            ->whereIn('sales_orders.store_id', $stores)
            ->whereIn('payments.store_id', $stores)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->sum('payment_allocations.amount'));

        // Accounting semantics: `outstanding` is the INVOICE-based receivable only. A confirmed order with no
        // issued invoice is a commercial exposure, not a receivable; it is reported separately so "0 due on
        // invoices" is never read as "the customer owes nothing". The two buckets are disjoint (an order is in
        // exactly one of them), so no debt is counted twice. Both are bounded SQL aggregates, computed per order.
        $needsReceivable = $canSeeInvoices && $canSeePayments;
        $needsUnbilled = $orders !== null && $canSeePayments;
        $position = ($needsReceivable || $needsUnbilled)
            ? $this->receivables->customerPositionAsOf($organization, (int) $customerId, $stores)
            : null;

        return [
            'unbilled_outstanding' => $needsUnbilled ? $position['unbilled'] : null,
            'sales_total' => $salesTotal,
            'invoiced_total' => $invoicedTotal,
            'paid_total' => $paidTotal,
            'outstanding' => $needsReceivable ? $position['receivable'] : null,
            // Money the customer paid above the net of the same invoice (e.g. after a credit note): owed TO the customer.
            'refund_obligation' => $needsReceivable ? $position['overcollected'] : null,
            'last_activity' => $orders === null ? null : $orders->max('sale_date'),
        ];
    }
}
