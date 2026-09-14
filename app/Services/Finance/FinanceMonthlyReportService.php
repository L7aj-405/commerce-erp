<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Read-only aggregates for the three headline metrics that never need an
 * invoice/payment-allocation join: Ventes, Facturation, Encaissements. Each
 * is a single DB-side SUM() — no rows are ever loaded into PHP and summed in
 * a Collection, and none of these three ever join across the
 * sales_orders / invoices / payments tables.
 *
 * Créances (which DOES need the SalesOrder <-> PaymentAllocation
 * relationship) lives in FinanceReceivablesService.
 */
class FinanceMonthlyReportService
{
    public function __construct(private readonly FinanceReceivablesService $receivables) {}

    /**
     * The full Situation mensuelle payload for one period: the three headline
     * sums plus the opening/closing receivables reconciliation.
     *
     * @return array<string, mixed>
     */
    public function situation(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $ventes = $this->ventesTotal($organization, $period, $store);
        $facturation = $this->facturationTotal($organization, $period, $store);
        $encaissements = $this->encaissementsTotal($organization, $period, $store);
        $creancesDebut = $this->receivables->totalAsOf($organization, $period->dayBeforeStart(), $store);
        $creancesFin = $this->receivables->totalAsOf($organization, $period->end, $store);

        // Reconciliation identity: début + facturation - encaissements should
        // equal fin, EXCEPT when a payment landed in this period against an
        // order that has no issued invoice yet ("payment before invoice" is a
        // legitimate state per the existing architecture — see
        // FinanceReceivablesService::unappliedPaymentsTotal()). Rather than
        // silently forcing the arithmetic to balance, the true gap is exposed
        // explicitly so the report stays traceable instead of a black box.
        $expectedFin = Decimal::subtract(Decimal::add($creancesDebut, $facturation), $encaissements);
        $unapplied = $this->receivables->unappliedPaymentsTotal($organization, $period, $store);

        return [
            'period' => $period->month,
            'label' => $period->label(),
            'ventes' => $ventes,
            'facturation' => $facturation,
            'encaissements' => $encaissements,
            'creances_debut' => $creancesDebut,
            'creances_fin' => $creancesFin,
            'reconciliation' => [
                'expected_fin' => $expectedFin,
                'variance' => Decimal::subtract($creancesFin, $expectedFin),
                'unapplied_payments' => $unapplied,
            ],
        ];
    }

    public function ventesTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return (string) $this->baseQuery('sales_orders', $organization, $store)
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereDate('sale_date', '>=', $period->start->toDateString())
            ->whereDate('sale_date', '<=', $period->end->toDateString())
            ->sum('total_incl_tax');
    }

    public function facturationTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return $this->facturationTotalBetween($organization, $period->start, $period->end, $store);
    }

    public function facturationTotalBetween(Organization $organization, Carbon $start, Carbon $end, ?Store $store): string
    {
        // status = Issued already IS "the current effective invoice": the
        // original is flipped to Superseded in the very same transaction that
        // issues its correction (IssueInvoiceAction), so at most one row per
        // sales_order_id ever carries status = Issued at once. No extra
        // "latest per order" logic is needed.
        return (string) $this->baseQuery('invoices', $organization, $store)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $start->toDateString())
            ->whereDate('invoice_date', '<=', $end->toDateString())
            ->sum('total_incl_tax');
    }

    public function encaissementsTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return (string) $this->baseQuery('payments', $organization, $store)
            ->where('status', PaymentStatus::Posted->value)
            ->whereDate('payment_date', '>=', $period->start->toDateString())
            ->whereDate('payment_date', '<=', $period->end->toDateString())
            ->sum('amount');
    }

    /** Confirmed SalesOrders for the period (Ventes drill-down), paginated. */
    public function ventesBreakdown(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereDate('sale_date', '>=', $period->start->toDateString())
            ->whereDate('sale_date', '<=', $period->end->toDateString())
            ->orderBy('sale_date')->orderBy('id')
            ->paginate($perPage)
            ->through(fn (SalesOrder $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'sale_date' => $order->sale_date->toDateString(),
                'customer' => trim($order->customer_company ?: $order->customer_name ?: '') ?: '—',
                'store_id' => $order->store_id,
                'total_incl_tax' => (string) $order->total_incl_tax,
                'payment_status' => $order->payment_status->value,
            ]);
    }

    /** Posted Payments for the period (Encaissements drill-down), paginated. */
    public function encaissementsBreakdown(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30)
    {
        return Payment::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', PaymentStatus::Posted->value)
            ->whereDate('payment_date', '>=', $period->start->toDateString())
            ->whereDate('payment_date', '<=', $period->end->toDateString())
            ->with(['financialAccount:id,name,code,type', 'allocations.salesOrder:id,order_number,customer_name,customer_company'])
            ->orderBy('payment_date')->orderBy('id')
            ->paginate($perPage)
            ->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'payment_date' => $payment->payment_date->toDateString(),
                'method' => $payment->method->value,
                'amount' => (string) $payment->amount,
                'financial_account' => $payment->financialAccount?->only(['id', 'name', 'code', 'type']),
                'store_id' => $payment->store_id,
                'order' => $payment->allocations->first()?->salesOrder?->only(['id', 'order_number', 'customer_name', 'customer_company']),
            ]);
    }

    /**
     * Memory-safe streaming of every confirmed SalesOrder in the period, for
     * the Ventes export sheet — never materializes the full result set.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function ventesCursor(Organization $organization, FinancePeriod $period, ?Store $store): LazyCollection
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereDate('sale_date', '>=', $period->start->toDateString())
            ->whereDate('sale_date', '<=', $period->end->toDateString())
            ->orderBy('sale_date')->orderBy('id')
            ->cursor()
            ->map(fn (SalesOrder $order) => [
                'order_number' => $order->order_number,
                'sale_date' => $order->sale_date->toDateString(),
                'customer' => trim($order->customer_company ?: $order->customer_name ?: '') ?: '—',
                'subtotal_excl_tax' => (string) $order->subtotal_excl_tax,
                'tax_total' => (string) $order->tax_total,
                'total_incl_tax' => (string) $order->total_incl_tax,
                'payment_status' => $order->payment_status->value,
            ]);
    }

    /**
     * Memory-safe streaming of every posted Payment in the period, for the
     * Encaissements export sheet. A plain query-builder join (not
     * Eloquent `with()`, which does not batch-eager-load across a `cursor()`
     * stream) keeps this to one query regardless of row count.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function encaissementsCursor(Organization $organization, FinancePeriod $period, ?Store $store): LazyCollection
    {
        return DB::table('payments')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'payments.financial_account_id')
            ->leftJoin('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'payment_allocations.sales_order_id')
            ->where('payments.organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('payments.store_id', $store->getKey()))
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())
            ->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->orderBy('payments.payment_date')->orderBy('payments.id')
            ->select([
                'payments.payment_number', 'payments.payment_date', 'payments.method', 'payments.amount',
                'financial_accounts.name as financial_account_name',
                'sales_orders.order_number', 'sales_orders.customer_name', 'sales_orders.customer_company',
            ])
            ->cursor()
            ->map(fn ($row) => [
                'payment_number' => $row->payment_number,
                'payment_date' => $row->payment_date,
                'method' => PaymentMethod::from($row->method)->documentLabel(),
                'amount' => (string) $row->amount,
                'financial_account' => $row->financial_account_name,
                'order_number' => $row->order_number,
                'customer' => $row->customer_company ?: $row->customer_name,
            ]);
    }

    private function baseQuery(string $table, Organization $organization, ?Store $store)
    {
        return DB::table($table)
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()));
    }
}
