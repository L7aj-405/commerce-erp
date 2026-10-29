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
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cheap customer financial summary shown on the customer profile (web) and by
 * the MCP balance tool: a handful of scoped aggregate SUM queries, never one
 * per row. Paid-so-far follows the same payment_allocations pattern
 * FinanceReceivablesService uses.
 *
 * W5.1: every figure is computed over the documents the VIEWER may see
 * (DocumentVisibility: view permission + the viewer's active stores), so a
 * document of another store never moves a number. A figure the viewer has no
 * permission for is null (never a partial or a zero that would hint at
 * hidden data): sales/last activity need sales_orders.view, invoiced needs
 * invoices.view, paid needs payments.view, outstanding needs both of the
 * latter.
 */
final class CustomerFinancialSummary
{
    public function __construct(private readonly DocumentVisibility $visibility) {}

    /** @return array{sales_total: ?string, invoiced_total: ?string, paid_total: ?string, outstanding: ?string, last_activity: ?string} */
    public function forCustomer(Customer $customer, User $viewer): array
    {
        $organization = $customer->organization;
        $customerId = $customer->getKey();
        $orders = $this->visibility->canView($viewer, $organization, SalesOrder::class)
            ? $this->visibility->visible($viewer, $organization, SalesOrder::class)->where('customer_id', $customerId) : null;

        $salesTotal = $orders === null ? null : Decimal::normalize((string) (clone $orders)
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->sum('total_incl_tax'));

        $invoicedTotal = ! $this->visibility->canView($viewer, $organization, Invoice::class) ? null : Decimal::normalize((string) $this->visibility
            ->visible($viewer, $organization, Invoice::class)
            ->where('customer_id', $customerId)
            ->where('status', InvoiceStatus::Issued->value)
            ->sum('total_incl_tax'));

        $stores = $this->visibility->storeIds($viewer, $organization) ?: [0];
        $paidTotal = ! $this->visibility->canView($viewer, $organization, Payment::class) ? null : Decimal::normalize((string) DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'payment_allocations.sales_order_id')
            ->where('sales_orders.organization_id', $organization->getKey())
            ->where('sales_orders.customer_id', $customerId)
            ->whereIn('sales_orders.store_id', $stores)
            ->whereIn('payments.store_id', $stores)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->sum('payment_allocations.amount'));

        // Same currency-precision rule as SalesOrderPaymentCalculator: a sub-centime residue is not owed.
        $outstanding = $invoicedTotal === null || $paidTotal === null ? null
            : Money::outstanding($invoicedTotal, $paidTotal, (string) config('platform.currency_code', 'MAD'));

        return [
            'sales_total' => $salesTotal,
            'invoiced_total' => $invoicedTotal,
            'paid_total' => $paidTotal,
            'outstanding' => $outstanding,
            'last_activity' => $orders === null ? null : $orders->max('sale_date'),
        ];
    }
}
