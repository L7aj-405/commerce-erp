<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Cheap customer financial summary shown on the customer profile: a handful of
 * scoped aggregate SUM queries, never one per row. Paid-so-far follows the
 * same payment_allocations pattern FinanceReceivablesService uses.
 */
final class CustomerFinancialSummary
{
    /** @return array{sales_total: string, invoiced_total: string, paid_total: string, outstanding: string, last_activity: ?string} */
    public function forCustomer(Customer $customer): array
    {
        $organizationId = $customer->organization_id;
        $customerId = $customer->getKey();

        $salesTotal = Decimal::normalize((string) SalesOrder::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->sum('total_incl_tax'));

        $invoicedTotal = Decimal::normalize((string) Invoice::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->where('status', InvoiceStatus::Issued->value)
            ->sum('total_incl_tax'));

        $paidTotal = Decimal::normalize((string) DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'payment_allocations.sales_order_id')
            ->where('sales_orders.organization_id', $organizationId)
            ->where('sales_orders.customer_id', $customerId)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->sum('payment_allocations.amount'));

        $outstanding = Decimal::compare($invoicedTotal, $paidTotal) > 0
            ? Decimal::subtract($invoicedTotal, $paidTotal)
            : '0.0000';

        $lastActivity = SalesOrder::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->max('sale_date');

        return [
            'sales_total' => $salesTotal,
            'invoiced_total' => $invoicedTotal,
            'paid_total' => $paidTotal,
            'outstanding' => $outstanding,
            'last_activity' => $lastActivity,
        ];
    }
}
