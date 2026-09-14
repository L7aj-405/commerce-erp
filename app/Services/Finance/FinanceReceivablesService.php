<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Créances (receivables), as-of a given date.
 *
 * Payments attach to a SalesOrder, never directly to an Invoice (see
 * PaymentAllocation — there is no invoice_id column at all). Because at most
 * one Invoice per order ever carries status=Issued (the "current effective"
 * invoice — see FinanceMonthlyReportService), every obligation here is
 * computed by matching an Issued invoice to ITS OWN sales_order_id's posted
 * allocations only — never by pooling all of an organization's payments
 * against all of its invoices. A payment can only ever reduce the receivable
 * of the specific order it was recorded against.
 */
class FinanceReceivablesService
{
    /** The organization-wide (or one-store) outstanding total as of a date — a single aggregate query. */
    public function totalAsOf(Organization $organization, Carbon $asOf, ?Store $store): string
    {
        // GREATEST(..., 0) floors EACH invoice's own outstanding at zero
        // before it is summed — never after. An overpaid invoice (e.g. its
        // total was later reduced by a correction below what the order had
        // already collected) must never leave a negative contribution that
        // offsets other, unrelated invoices' positive balances in the total.
        $result = $this->obligationsQuery($organization, $asOf, $store)
            ->selectRaw('COALESCE(SUM(GREATEST(invoices.total_incl_tax - COALESCE(paid.paid_amount, 0), 0)), 0) as outstanding')
            ->value('outstanding');

        return (string) $result;
    }

    /**
     * Paginated per-invoice breakdown as of a date, for the "Créances"
     * drill-down. Two DB round trips total regardless of page size — the
     * aggregate join for amounts, plus the page's own row fetch.
     */
    public function breakdownAsOf(Organization $organization, Carbon $asOf, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        return $this->obligationsQuery($organization, $asOf, $store)
            ->select([
                'invoices.id',
                'invoices.invoice_number',
                'invoices.invoice_date',
                'invoices.customer_name',
                'invoices.customer_company',
                'invoices.sales_order_id',
                'invoices.store_id',
                'invoices.total_incl_tax',
                DB::raw('COALESCE(paid.paid_amount, 0) as paid_amount'),
            ])
            ->orderBy('invoices.invoice_date')
            ->orderBy('invoices.id')
            ->paginate($perPage)
            ->through(fn ($row) => $this->toOutstandingRow($row));
    }

    /**
     * Payments posted within the period whose SalesOrder has no currently
     * Issued invoice at all ("payment before invoice" — an explicitly
     * legitimate state). These count fully in the global Encaissements total
     * but have no invoice obligation to net against yet, so they are the
     * reason the "début + facturation - encaissements = fin" identity can
     * legitimately not balance in a given month. Exposed for traceability,
     * never hidden.
     */
    public function unappliedPaymentsTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        $result = DB::table('payments')
            ->where('payments.organization_id', $organization->getKey())
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())
            ->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->when($store, fn ($query) => $query->where('payments.store_id', $store->getKey()))
            ->whereExists(function ($query) use ($organization) {
                $query->select(DB::raw(1))
                    ->from('payment_allocations')
                    ->whereColumn('payment_allocations.payment_id', 'payments.id')
                    ->whereNotExists(function ($query) use ($organization) {
                        $query->select(DB::raw(1))
                            ->from('invoices')
                            ->whereColumn('invoices.sales_order_id', 'payment_allocations.sales_order_id')
                            ->where('invoices.organization_id', $organization->getKey())
                            ->where('invoices.status', InvoiceStatus::Issued->value);
                    });
            })
            ->sum('payments.amount');

        return (string) $result;
    }

    /**
     * Batch-enrich an already-fetched set of sales_order_ids with their
     * posted-and-applicable-as-of-date paid amount, in exactly one query
     * regardless of how many orders are passed in. Used by
     * FinanceInvoiceReadModel so per-invoice paid/outstanding/fully_paid_at
     * never triggers N+1 — never call this inside a loop.
     *
     * @param  list<int>  $salesOrderIds
     * @return array<int, string> sales_order_id => paid amount as of $asOf
     */
    public function paidAmountsBySalesOrder(array $salesOrderIds, Carbon $asOf): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        // `pluck()` strips a raw expression down to whatever follows its last
        // "." — for an unaliased `SUM(payment_allocations.amount)` that means
        // it looks up a property that was never returned by the DB driver
        // (the driver names the column after the raw expression itself), so
        // every row plucks as null/undefined. An explicit `as amount` alias
        // makes the plucked key match the column PDO actually returns.
        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.sales_order_id', $salesOrderIds)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '<=', $asOf->toDateString())
            ->groupBy('payment_allocations.sales_order_id')
            ->select('payment_allocations.sales_order_id', DB::raw('SUM(payment_allocations.amount) as amount'))
            ->pluck('amount', 'payment_allocations.sales_order_id')
            ->map(fn ($amount) => (string) $amount)
            ->all();
    }

    /**
     * Every posted allocation for the given sales orders, ordered by payment
     * date, for deriving fully_paid_at by walking a running sum in PHP over
     * an already-fetched (bounded) batch — one query total, never per-order.
     * `payment_id` is included so a caller (e.g. the CA encaissé display
     * classification — "Paiement comptant" / "partiel" / "Solde") can locate
     * exactly which allocation in the order's payment history one specific
     * row corresponds to, without a second lookup.
     *
     * @param  list<int>  $salesOrderIds
     * @return array<int, list<array{payment_id: int, payment_date: string, amount: string}>> sales_order_id => ordered allocations
     */
    public function orderedAllocationsBySalesOrder(array $salesOrderIds): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.sales_order_id', $salesOrderIds)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->orderBy('payments.payment_date')
            ->orderBy('payments.id')
            ->get(['payment_allocations.sales_order_id', 'payments.id as payment_id', 'payments.payment_date', 'payment_allocations.amount'])
            ->groupBy('sales_order_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'payment_id' => (int) $row->payment_id,
                'payment_date' => (string) $row->payment_date,
                'amount' => (string) $row->amount,
            ])->all())
            ->all();
    }

    /**
     * Every outstanding-as-of-date invoice row, memory-safely streamed for
     * export (never materialized as one giant array/collection).
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function cursorAsOf(Organization $organization, Carbon $asOf, ?Store $store): LazyCollection
    {
        return $this->obligationsQuery($organization, $asOf, $store)
            ->select([
                'invoices.id', 'invoices.invoice_number', 'invoices.invoice_date',
                'invoices.customer_name', 'invoices.customer_company', 'invoices.sales_order_id', 'invoices.store_id',
                'invoices.subtotal_excl_tax', 'invoices.tax_total', 'invoices.total_incl_tax',
                DB::raw('COALESCE(paid.paid_amount, 0) as paid_amount'),
            ])
            ->orderBy('invoices.invoice_date')
            ->orderBy('invoices.id')
            ->cursor()
            ->map(fn ($row) => $this->toOutstandingRow($row));
    }

    private function obligationsQuery(Organization $organization, Carbon $asOf, ?Store $store)
    {
        $paidSubquery = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.organization_id', $organization->getKey())
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '<=', $asOf->toDateString())
            ->groupBy('payment_allocations.sales_order_id')
            ->select('payment_allocations.sales_order_id', DB::raw('SUM(payment_allocations.amount) as paid_amount'));

        return DB::table('invoices')
            ->leftJoinSub($paidSubquery, 'paid', 'paid.sales_order_id', '=', 'invoices.sales_order_id')
            ->where('invoices.organization_id', $organization->getKey())
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereDate('invoices.invoice_date', '<=', $asOf->toDateString())
            ->when($store, fn ($query) => $query->where('invoices.store_id', $store->getKey()));
    }

    private function toOutstandingRow(object $row): array
    {
        $outstanding = $this->nonNegative(Decimal::subtract($row->total_incl_tax, $row->paid_amount));
        $status = $this->status($row->total_incl_tax, $row->paid_amount);

        return [
            'id' => $row->id,
            'invoice_number' => $row->invoice_number,
            'invoice_date' => $row->invoice_date,
            'customer_name' => $row->customer_name,
            'customer_company' => $row->customer_company,
            'sales_order_id' => $row->sales_order_id,
            'store_id' => $row->store_id,
            'subtotal_excl_tax' => isset($row->subtotal_excl_tax) ? (string) $row->subtotal_excl_tax : null,
            'tax_total' => isset($row->tax_total) ? (string) $row->tax_total : null,
            'total_incl_tax' => (string) $row->total_incl_tax,
            'paid_amount' => (string) $row->paid_amount,
            'outstanding' => $outstanding,
            'status' => $status,
        ];
    }

    private function status(string $total, string $paid): string
    {
        if (Decimal::compare($paid, '0.0000') <= 0) {
            return 'unpaid';
        }

        return Decimal::compare($paid, $total) >= 0 ? 'paid' : 'partial';
    }

    /** A receivable can never be negative — an overpayment (e.g. a correction reduced the total below what was already collected) floors at zero, it never offsets another invoice's balance. */
    private function nonNegative(string $amount): string
    {
        return Decimal::compare($amount, '0.0000') < 0 ? '0.0000' : $amount;
    }
}
