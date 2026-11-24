<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
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
    /** "No date bound" for customerPositionAsOf(): far enough to include any dated document, safe for +1 day maths. */
    private const OPEN_ENDED_AS_OF = '2999-12-31';

    public function __construct(private readonly SalesOrderPaymentCalculator $payments) {}

    /*
    |--------------------------------------------------------------------------
    | Two receivable concepts — deliberately NOT merged
    |--------------------------------------------------------------------------
    |
    | 1. ORDER EXPOSURE ("Créances clients", executive dashboards, expected
    |    collection picker): what customers still owe on CONFIRMED sales
    |    orders, whether or not an invoice was issued.
    |
    |      commercial total = max(order TTC − received returns TTC, 0)
    |      net paid         = posted collections − posted refunds
    |      outstanding      = commercial total − net paid, counted only when
    |                         it is payable at the currency precision
    |                         (Money::outstanding: 0.0049 MAD is settled)
    |
    |    As of a date: collections by payment_date, refunds by refund_date,
    |    returns by received_at before the end of that LOCAL day, orders by
    |    sale_date. Per-order rule: SalesOrderPaymentCalculator::summaryFromAmounts.
    |
    | 2. ISSUED-INVOICE DEBT ("Situation" créances début / fin, Créances
    |    drill-down by invoice): the fiscal receivable carried by the currently
    |    effective ISSUED invoice of each order (see totalAsOf()):
    |
    |      issued invoice TTC − issued credit notes − net paid (as of)
    |
    |    floored at zero per invoice; the excess is a separate refund
    |    obligation (refundObligationAsOf()), never a negative receivable.
    |
    | They legitimately differ: an unpaid confirmed order without an invoice
    | is exposure but not invoice debt; payments recorded before the invoice
    | is issued are "unapplied" (unappliedPaymentsTotal()).
    */

    /**
     * Order exposure as of a date: the paginated rows, the total and the
     * unpaid / partially paid split — computed in the database (one aggregate
     * query + one page query), never by loading every order in memory.
     *
     * @return array{total: string, orders: LengthAwarePaginator, status_counts: array{unpaid:int,partially_paid:int}, status_amounts: array{unpaid:string,partially_paid:string}}
     */
    public function salesOrderReceivablesAsOf(Organization $organization, Carbon $asOf, ?Store $store, int $perPage = 30): array
    {
        $summary = $this->orderExposureSummaryAsOf($organization, $asOf, $store);

        $page = $this->orderExposureQuery($organization, $asOf, $store)
            ->orderBy('sales_orders.sale_date')
            ->orderBy('sales_orders.id')
            ->paginate($perPage)
            ->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (SalesOrder $order) => $this->exposureRow($order)));

        return [
            'total' => $summary['total'],
            'orders' => $page,
            'status_counts' => $summary['status_counts'],
            'status_amounts' => $summary['status_amounts'],
        ];
    }

    /**
     * Confirmed orders with a payable balance and NO issued/superseded invoice (the commercial exposure, disjoint from the
     * invoice receivable), paginated, oldest due date first (undated last).
     *
     * @return LengthAwarePaginator<int, array<string,mixed>>
     */
    public function unbilledOrdersAsOf(Organization $organization, Carbon $asOf, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        $organizationId = (int) $organization->getKey();
        $page = $this->orderExposureQuery($organization, $asOf, $store)
            ->whereNotExists(fn ($bill) => $bill->selectRaw('1')->from('invoices as bill')
                ->whereColumn('bill.sales_order_id', 'sales_orders.id')->where('bill.organization_id', $organizationId)
                ->whereIn('bill.status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value]))
            ->orderByRaw('CASE WHEN sales_orders.due_date IS NULL THEN 1 ELSE 0 END')->orderBy('sales_orders.due_date')->orderBy('sales_orders.id')
            ->paginate($perPage)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (SalesOrder $order) => $this->exposureRow($order)));

        return $page;
    }

    /**
     * Order exposure totals only (dashboards): one aggregate query.
     *
     * @return array{total: string, status_counts: array{unpaid:int,partially_paid:int}, status_amounts: array{unpaid:string,partially_paid:string}}
     */
    public function orderExposureSummaryAsOf(Organization $organization, Carbon $asOf, ?Store $store): array
    {
        $row = DB::query()->fromSub($this->orderExposureQuery($organization, $asOf, $store)->toBase(), 'exposure')
            ->selectRaw('COALESCE(SUM(outstanding_raw), 0) as total')
            ->selectRaw('SUM(CASE WHEN net_paid_raw = 0 THEN 1 ELSE 0 END) as unpaid_count')
            ->selectRaw('SUM(CASE WHEN net_paid_raw <> 0 THEN 1 ELSE 0 END) as partial_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN net_paid_raw = 0 THEN outstanding_raw ELSE 0 END), 0) as unpaid_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN net_paid_raw <> 0 THEN outstanding_raw ELSE 0 END), 0) as partial_amount')
            ->first();

        return [
            'total' => Decimal::normalize((string) ($row->total ?? '0')),
            'status_counts' => ['unpaid' => (int) ($row->unpaid_count ?? 0), 'partially_paid' => (int) ($row->partial_count ?? 0)],
            'status_amounts' => [
                'unpaid' => Decimal::normalize((string) ($row->unpaid_amount ?? '0')),
                'partially_paid' => Decimal::normalize((string) ($row->partial_amount ?? '0')),
            ],
        ];
    }

    /**
     * Confirmed orders with a payable order exposure as of a date, with their
     * aggregated components (collected_amount, refunded_amount,
     * returned_amount, last_payment_date) and the derived outstanding_raw /
     * net_paid_raw columns. Callers order / paginate / limit it.
     *
     * @return Builder<SalesOrder>
     */
    public function orderExposureQuery(Organization $organization, Carbon $asOf, ?Store $store)
    {
        $organizationId = (int) $organization->getKey();
        $date = $asOf->toDateString();

        $collections = $this->allocationLedger($date)
            ->join('payments', function ($join) {
                $join->on('payments.id', '=', 'payment_allocations.payment_id')
                    ->on('payments.organization_id', '=', 'payment_allocations.organization_id');
            })
            ->where('payment_allocations.organization_id', $organizationId)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->groupBy('payment_allocations.sales_order_id')
            ->select('payment_allocations.sales_order_id')
            ->selectRaw('SUM(payment_allocations.amount) as collected_amount')
            ->selectRaw('MAX(payment_allocations.effective_date) as last_payment_date');
        $refunds = DB::table('payment_refunds')
            ->where('organization_id', $organizationId)
            ->where('status', 'posted')
            ->whereDate('refund_date', '<=', $date)
            ->groupBy('sales_order_id')
            ->select('sales_order_id')
            ->selectRaw('SUM(amount) as refunded_amount');
        $returns = DB::table('customer_returns')
            ->where('organization_id', $organizationId)
            ->where('status', 'received')
            ->where('received_at', '<', SalesOrderPaymentCalculator::endOfLocalDayUtc($organizationId, $asOf))
            ->groupBy('sales_order_id')
            ->select('sales_order_id')
            ->selectRaw('SUM(total_incl_tax) as returned_amount');

        $outstanding = $this->outstandingSql();
        $netPaid = $this->netPaidSql();

        return SalesOrder::query()
            ->withoutGlobalScopes()
            ->whereNull('sales_orders.sandbox_id')
            ->leftJoinSub($collections, 'exp_collections', 'exp_collections.sales_order_id', '=', 'sales_orders.id')
            ->leftJoinSub($refunds, 'exp_refunds', 'exp_refunds.sales_order_id', '=', 'sales_orders.id')
            ->leftJoinSub($returns, 'exp_returns', 'exp_returns.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.organization_id', $organizationId)
            ->where('sales_orders.status', SalesOrderStatus::Confirmed->value)
            ->whereDate('sales_orders.sale_date', '<=', $date)
            ->when($store, fn ($query) => $query->where('sales_orders.store_id', $store->getKey()))
            ->whereRaw("({$outstanding}) >= (".Money::payableThresholdSql('sales_orders.currency_code').')')
            ->select([
                'sales_orders.id', 'sales_orders.organization_id', 'sales_orders.store_id', 'sales_orders.order_number',
                'sales_orders.sale_date', 'sales_orders.customer_name', 'sales_orders.customer_company',
                'sales_orders.customer_id', 'sales_orders.total_incl_tax', 'sales_orders.currency_code', 'sales_orders.status', 'sales_orders.due_date',
            ])
            ->selectRaw('COALESCE(exp_collections.collected_amount, 0) as collected_amount')
            ->selectRaw('COALESCE(exp_refunds.refunded_amount, 0) as refunded_amount')
            ->selectRaw('COALESCE(exp_returns.returned_amount, 0) as returned_amount')
            ->selectRaw('exp_collections.last_payment_date as last_payment_date')
            ->selectRaw("({$outstanding}) as outstanding_raw")
            ->selectRaw("({$netPaid}) as net_paid_raw");
    }

    /** max(order TTC − returned, 0) − (collected − refunded), on the joined aggregates. */
    private function outstandingSql(): string
    {
        $returned = 'COALESCE(exp_returns.returned_amount, 0)';
        $commercial = "CASE WHEN sales_orders.total_incl_tax - {$returned} > 0 THEN sales_orders.total_incl_tax - {$returned} ELSE 0 END";

        return "{$commercial} - ({$this->netPaidSql()})";
    }

    private function netPaidSql(): string
    {
        return 'COALESCE(exp_collections.collected_amount, 0) - COALESCE(exp_refunds.refunded_amount, 0)';
    }

    /**
     * One page row, recomputed from the aggregated components with the
     * canonical per-order rule (exact 4-decimal arithmetic in PHP).
     *
     * @return array<string,mixed>
     */
    private function exposureRow(SalesOrder $order): array
    {
        $summary = $this->payments->summaryFromAmounts(
            $order,
            (string) $order->getAttribute('collected_amount'),
            (string) $order->getAttribute('refunded_amount'),
            (string) $order->getAttribute('returned_amount'),
            $order->getAttribute('last_payment_date') !== null
                ? Carbon::parse((string) $order->getAttribute('last_payment_date'))->toDateString()
                : null,
        );

        return [
            'id' => $order->getKey(),
            'order_number' => $order->order_number,
            'sale_date' => $order->sale_date->toDateString(),
            'customer' => trim($order->customer_company ?: $order->customer_name ?: '') ?: '—',
            'store_id' => $order->store_id,
            'total_incl_tax' => $summary['commercial_total'],
            'paid_amount' => $summary['paid'],
            'outstanding' => $summary['remaining'],
            'payment_status' => $summary['status'],
            'last_payment_date' => $summary['last_payment_date'],
            // Additive (Finance clients): the order due date stamped at confirmation (null when none) and overdue flag.
            'due_date' => $order->due_date?->toDateString(),
            'is_overdue' => $order->due_date !== null && Decimal::compare($summary['remaining'], '0') > 0 && $order->due_date->toDateString() < Carbon::now()->toDateString(),
            'customer_id' => $order->customer_id,
        ];
    }

    /** The organization-wide (or one-store) outstanding total as of a date — a single aggregate query. */
    public function totalAsOf(Organization $organization, Carbon $asOf, ?Store $store): string
    {
        // The CASE expression floors EACH invoice's own outstanding at zero
        // before it is summed — never after. An overpaid invoice (e.g. its
        // total was later reduced by a correction below what the order had
        // already collected) must never leave a negative contribution that
        // offsets other, unrelated invoices' positive balances in the total.
        // CASE is deliberately used instead of MySQL's GREATEST() so the
        // authoritative query behaves identically on MySQL and SQLite.
        $netOutstanding = 'invoices.total_incl_tax - COALESCE(credits.credited_amount, 0) - COALESCE(paid.paid_amount, 0)';
        $result = $this->obligationsQuery($organization, $asOf, $store)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$netOutstanding} > 0 THEN {$netOutstanding} ELSE 0 END), 0) as outstanding")
            ->value('outstanding');

        return Decimal::normalize((string) $result);
    }

    /**
     * One customer's position, computed ORDER BY ORDER and summed in two bounded SQL aggregates.
     *
     *  - `receivable`    : Σ over the customer's ISSUED invoices of max(invoice − its order's credit notes − its
     *                      order's net collections, 0). Same operands and same obligationsQuery() as
     *                      totalAsOf()/breakdownAsOf(), merely restricted to one customer and a set of stores — with
     *                      ONE deliberate difference: SETTLEMENT precision. A difference that rounds to zero at the
     *                      currency precision (0.0001 MAD) is not a payable amount (Money::outstanding(), the rule
     *                      that lets such an order be invoiced and that orderExposureQuery()/salesOrderReceivablesAsOf()
     *                      already apply), so it is not a receivable here. totalAsOf() stays LEDGER-exact because the
     *                      monthly situation proves "début + facturation − encaissements = fin" to the 0.0001.
     *  - `overcollected` : Σ of what customers paid ABOVE the net invoice of the same order (a distinct refund
     *                      obligation — see refundObligationAsOf(), same settlement threshold as `receivable`).
     *                      It is reported, never clamped away.
     *  - `unbilled`      : Σ of the remaining balance of the customer's confirmed orders that have NO issued or
     *                      superseded invoice (orderExposureQuery). Disjoint from `receivable` by construction:
     *                      an order is in exactly one of the two buckets, so a debt is never counted twice.
     *
     * Collections of one order can never reduce another order's balance: every payment/refund/credit-note
     * aggregate is grouped by sales_order_id and joined only to that order's own row. Test Mode rows are excluded
     * (sandbox_id IS NULL), every table is filtered by organization_id, and `$storeIds` is the caller's visible
     * stores (an empty list yields zeros — never "everything").
     *
     * `$asOf = null` means "every document, whatever its date": a customer profile is a current position, not a
     * period report, and a future-dated order/invoice (backdating permission allows any date) is still owed.
     *
     * @param  list<int>  $storeIds
     * @return array{receivable: string, overcollected: string, unbilled: string}
     */
    public function customerPositionAsOf(Organization $organization, int $customerId, array $storeIds, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::parse(self::OPEN_ENDED_AS_OF);
        $zero = ['receivable' => '0.0000', 'overcollected' => '0.0000', 'unbilled' => '0.0000'];
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        if ($storeIds === []) {
            return $zero;
        }
        $organizationId = (int) $organization->getKey();

        // Same operands as totalAsOf() / refundObligationAsOf(); parenthesized so SQL evaluates a − (b − c).
        // A difference that rounds to zero at the currency precision (0.0001 MAD) is not a payable amount: it is
        // neither a receivable nor a refund obligation (the same rule as Money::outstanding(), via its SQL twin).
        // ROUND(…, 4) keeps the internal 4-decimal scale exact — SQLite subtracts DECIMALs as binary floats
        // (13306.0001 − 13306.0000 = 9.99999999e-05), MySQL is exact.
        $netBilled = '(invoices.total_incl_tax - COALESCE(credits.credited_amount, 0))';
        $netCollected = '(COALESCE(paid.paid_amount, 0))';
        $threshold = Money::payableThresholdSql('invoices.currency_code');
        $due = "ROUND({$netBilled} - {$netCollected}, 4)";
        $over = "ROUND({$netCollected} - {$netBilled}, 4)";
        $invoices = $this->obligationsQuery($organization, $asOf, null)
            ->where('invoices.customer_id', $customerId)
            ->whereIn('invoices.store_id', $storeIds)
            ->selectRaw("ROUND(COALESCE(SUM(CASE WHEN {$due} >= ({$threshold}) THEN {$due} ELSE 0 END), 0), 4) as receivable")
            ->selectRaw("ROUND(COALESCE(SUM(CASE WHEN {$over} >= ({$threshold}) THEN {$over} ELSE 0 END), 0), 4) as overcollected")
            ->first();

        $exposure = $this->orderExposureQuery($organization, $asOf, null)
            ->where('sales_orders.customer_id', $customerId)
            ->whereIn('sales_orders.store_id', $storeIds)
            ->whereNotExists(fn ($bill) => $bill->selectRaw('1')->from('invoices as bill')
                ->whereColumn('bill.sales_order_id', 'sales_orders.id')
                ->where('bill.organization_id', $organizationId)
                ->whereIn('bill.status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value]));
        $unbilled = DB::query()->fromSub($exposure->toBase(), 'exposure')
            ->selectRaw('ROUND(COALESCE(SUM(ROUND(outstanding_raw, 4)), 0), 4) as total')
            ->value('total');

        return [
            'receivable' => Decimal::normalize((string) ($invoices->receivable ?? '0')),
            'overcollected' => $this->nonNegative(Decimal::normalize((string) ($invoices->overcollected ?? '0'))),
            'unbilled' => Decimal::normalize((string) ($unbilled ?? '0')),
        ];
    }

    /**
     * BULK customer positions (Finance clients accounts index / aging): the same operands as customerPositionAsOf()
     * (order-specific credits and net collections, settlement-precision threshold, Test Mode excluded) but grouped by
     * customer and currency in TWO bounded aggregate queries, whatever the number of customers. Never one query per
     * customer and never pooled across orders.
     *
     * Per (customer, currency): invoiced_gross, credited, net_invoiced, paid (net collections of the invoiced orders),
     * receivable (sum per invoice of max(net - paid, 0)), overdue (receivable whose due_date < $today), undated (receivable
     * with NO due date - never silently dated), not_due, bands 1-30 / 31-60 / 61-90 / 90+ (days past due as of $today),
     * overcollected (refund obligation) and unbilled (confirmed orders without an issued/superseded invoice).
     * `$asOf = null` means every document whatever its date (current position); `$today` is the overdue basis date.
     *
     * @param  list<int>  $customerIds
     * @param  list<int>  $storeIds
     * @return array<int, array<string, mixed>> keyed by customer id; `mixed_currency` is true when several currencies exist (figures then null)
     */
    public function customerPositionsAsOf(Organization $organization, array $customerIds, array $storeIds, ?Carbon $asOf = null, ?Carbon $today = null): array
    {
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        if ($customerIds === [] || $storeIds === []) {
            return [];
        }
        $asOf ??= Carbon::parse(self::OPEN_ENDED_AS_OF);
        $today ??= $asOf->copy();
        if ($today->year >= 2999) {
            $today = Carbon::now();
        }
        $day = $today->toDateString();
        $b1a = $today->copy()->subDays(30)->toDateString();
        $b1b = $today->copy()->subDays(1)->toDateString();
        $b2a = $today->copy()->subDays(60)->toDateString();
        $b2b = $today->copy()->subDays(31)->toDateString();
        $b3a = $today->copy()->subDays(90)->toDateString();
        $b3b = $today->copy()->subDays(61)->toDateString();
        $b4 = $today->copy()->subDays(91)->toDateString();
        $organizationId = (int) $organization->getKey();

        $netBilled = '(invoices.total_incl_tax - COALESCE(credits.credited_amount, 0))';
        $netCollected = '(COALESCE(paid.paid_amount, 0))';
        $threshold = Money::payableThresholdSql('invoices.currency_code');
        $due = "ROUND({$netBilled} - {$netCollected}, 4)";
        $over = "ROUND({$netCollected} - {$netBilled}, 4)";
        $open = "{$due} >= ({$threshold})";
        $sum = fn (string $condition) => "ROUND(COALESCE(SUM(CASE WHEN {$condition} THEN {$due} ELSE 0 END), 0), 4)";

        $invoiceRows = $this->obligationsQuery($organization, $asOf, null)
            ->whereIn('invoices.customer_id', $customerIds)->whereIn('invoices.store_id', $storeIds)
            ->groupBy('invoices.customer_id', 'invoices.currency_code')
            ->select('invoices.customer_id', 'invoices.currency_code')
            ->selectRaw('ROUND(COALESCE(SUM(invoices.total_incl_tax), 0), 4) as invoiced_gross')
            ->selectRaw('ROUND(COALESCE(SUM(COALESCE(credits.credited_amount, 0)), 0), 4) as credited')
            ->selectRaw('ROUND(COALESCE(SUM(COALESCE(paid.paid_amount, 0)), 0), 4) as paid')
            ->selectRaw($sum($open).' as receivable')
            ->selectRaw($sum("{$open} AND invoices.due_date IS NOT NULL AND invoices.due_date < ?").' as overdue', [$day])
            ->selectRaw($sum("{$open} AND invoices.due_date IS NULL").' as undated')
            ->selectRaw($sum("{$open} AND invoices.due_date >= ?").' as not_due', [$day])
            ->selectRaw($sum("{$open} AND invoices.due_date >= ? AND invoices.due_date <= ?").' as band_1_30', [$b1a, $b1b])
            ->selectRaw($sum("{$open} AND invoices.due_date >= ? AND invoices.due_date <= ?").' as band_31_60', [$b2a, $b2b])
            ->selectRaw($sum("{$open} AND invoices.due_date >= ? AND invoices.due_date <= ?").' as band_61_90', [$b3a, $b3b])
            ->selectRaw($sum("{$open} AND invoices.due_date < ?").' as band_90_plus', [$b4])
            ->selectRaw("ROUND(COALESCE(SUM(CASE WHEN {$over} >= ({$threshold}) THEN {$over} ELSE 0 END), 0), 4) as overcollected")
            ->get();

        $exposure = $this->orderExposureQuery($organization, $asOf, null)
            ->whereIn('sales_orders.customer_id', $customerIds)->whereIn('sales_orders.store_id', $storeIds)
            ->whereNotExists(fn ($bill) => $bill->selectRaw('1')->from('invoices as bill')
                ->whereColumn('bill.sales_order_id', 'sales_orders.id')->where('bill.organization_id', $organizationId)
                ->whereIn('bill.status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value]));
        $unbilledRows = DB::query()->fromSub($exposure->toBase(), 'exposure')
            ->groupBy('customer_id', 'currency_code')->select('customer_id', 'currency_code')
            ->selectRaw('ROUND(COALESCE(SUM(ROUND(outstanding_raw, 4)), 0), 4) as unbilled')
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date < ? THEN ROUND(outstanding_raw, 4) ELSE 0 END), 0), 4) as unbilled_overdue', [$day])
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN due_date IS NULL THEN ROUND(outstanding_raw, 4) ELSE 0 END), 0), 4) as unbilled_undated')
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN due_date >= ? THEN ROUND(outstanding_raw, 4) ELSE 0 END), 0), 4) as unbilled_not_due', [$day])
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN due_date >= ? AND due_date <= ? THEN ROUND(outstanding_raw, 4) ELSE 0 END), 0), 4) as unbilled_band_1_30', [$b1a, $b1b])
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN due_date >= ? AND due_date <= ? THEN ROUND(outstanding_raw, 4) ELSE 0 END), 0), 4) as unbilled_band_31_60', [$b2a, $b2b])
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN due_date >= ? AND due_date <= ? THEN ROUND(outstanding_raw, 4) ELSE 0 END), 0), 4) as unbilled_band_61_90', [$b3a, $b3b])
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN due_date < ? THEN ROUND(outstanding_raw, 4) ELSE 0 END), 0), 4) as unbilled_band_90_plus', [$b4])
            ->get();

        $zero = ['invoiced_gross', 'credited', 'paid', 'receivable', 'overdue', 'undated', 'not_due', 'band_1_30', 'band_31_60', 'band_61_90', 'band_90_plus', 'overcollected', 'unbilled',
            'unbilled_overdue', 'unbilled_undated', 'unbilled_not_due', 'unbilled_band_1_30', 'unbilled_band_31_60', 'unbilled_band_61_90', 'unbilled_band_90_plus'];
        $out = [];
        $merge = function (object $row) use (&$out, $zero) {
            $id = (int) $row->customer_id;
            $out[$id]['by_currency'][(string) $row->currency_code] ??= array_fill_keys($zero, '0.0000');
            foreach ($zero as $column) {
                if (property_exists($row, $column)) {
                    $out[$id]['by_currency'][(string) $row->currency_code][$column] = Decimal::normalize((string) $row->{$column});
                }
            }
        };
        foreach ($invoiceRows as $row) {
            $merge($row);
        }
        foreach ($unbilledRows as $row) {
            $merge($row);
        }
        $result = [];
        foreach ($out as $id => $data) {
            $currencies = array_keys($data['by_currency']);
            $mixed = count($currencies) > 1;
            $figures = $mixed ? array_fill_keys($zero, null) : $data['by_currency'][$currencies[0]];
            $figures['net_invoiced'] = $mixed ? null : Decimal::subtract($figures['invoiced_gross'], $figures['credited']);
            $result[$id] = $figures + ['currency_code' => $mixed ? null : $currencies[0], 'mixed_currency' => $mixed];
        }

        return $result;
    }

    /**
     * Customer money retained above the net issued-document obligation.
     * This is not a negative receivable: it is a distinct refund obligation.
     */
    public function refundObligationAsOf(Organization $organization, Carbon $asOf, ?Store $store): string
    {
        // Keep each economic operand parenthesized. Without the parentheses,
        // SQL evaluates `net collected - invoice total - credits` from left
        // to right instead of `net collected - (invoice total - credits)`.
        $netBilled = '(invoices.total_incl_tax - COALESCE(credits.credited_amount, 0))';
        $netCollected = '(COALESCE(paid.paid_amount, 0))';
        $result = $this->obligationsQuery($organization, $asOf, $store)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$netCollected} > {$netBilled} THEN {$netCollected} - {$netBilled} ELSE 0 END), 0) as obligation")
            ->value('obligation');

        return $this->nonNegative(Decimal::normalize((string) $result));
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
                DB::raw('invoices.total_incl_tax - COALESCE(credits.credited_amount, 0) as total_incl_tax'),
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
        $collections = DB::table('payments')
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
                            ->from('invoices')->whereNull('invoices.sandbox_id')
                            ->whereColumn('invoices.sales_order_id', 'payment_allocations.sales_order_id')
                            ->where('invoices.organization_id', $organization->getKey())
                            ->where('invoices.status', InvoiceStatus::Issued->value);
                    });
            })
            ->sum('payments.amount');

        $refunds = DB::table('payment_refunds')
            ->where('payment_refunds.organization_id', $organization->getKey())
            ->where('payment_refunds.status', 'posted')
            ->whereDate('payment_refunds.refund_date', '>=', $period->start->toDateString())
            ->whereDate('payment_refunds.refund_date', '<=', $period->end->toDateString())
            ->when($store, fn ($query) => $query->where('payment_refunds.store_id', $store->getKey()))
            ->whereNotExists(function ($query) use ($organization) {
                $query->select(DB::raw(1))
                    ->from('invoices')->whereNull('invoices.sandbox_id')
                    ->whereColumn('invoices.sales_order_id', 'payment_refunds.sales_order_id')
                    ->where('invoices.organization_id', $organization->getKey())
                    ->where('invoices.status', InvoiceStatus::Issued->value);
            })
            ->sum('payment_refunds.amount');

        return Decimal::subtract((string) $collections, (string) $refunds);
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
        $refunds = DB::table('payment_refunds')
            ->whereIn('sales_order_id', $salesOrderIds)
            ->where('status', 'posted')
            ->whereDate('refund_date', '<=', $asOf->toDateString())
            ->groupBy('sales_order_id')
            ->select('sales_order_id', DB::raw('SUM(amount) as refunded_amount'));

        return $this->allocationLedger($asOf->toDateString())
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->leftJoinSub($refunds, 'refunds', 'refunds.sales_order_id', '=', 'payment_allocations.sales_order_id')
            ->whereIn('payment_allocations.sales_order_id', $salesOrderIds)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->groupBy('payment_allocations.sales_order_id')
            ->select('payment_allocations.sales_order_id', DB::raw('SUM(payment_allocations.amount) - COALESCE(MAX(refunds.refunded_amount), 0) as amount'))
            ->pluck('amount', 'payment_allocations.sales_order_id')
            ->map(fn ($amount) => Decimal::normalize((string) $amount))
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
    public function orderedAllocationsBySalesOrder(array $salesOrderIds, ?Carbon $asOf = null): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        return $this->allocationLedger($asOf?->toDateString() ?? '9999-12-31')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.sales_order_id', $salesOrderIds)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->orderBy('payment_allocations.effective_date')
            ->orderBy('payments.id')
            ->get(['payment_allocations.sales_order_id', 'payments.id as payment_id', 'payment_allocations.effective_date as payment_date', 'payment_allocations.amount'])
            ->groupBy('sales_order_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'payment_id' => (int) $row->payment_id,
                'payment_date' => Carbon::parse((string) $row->payment_date)->toDateString(),
                'amount' => Decimal::normalize((string) $row->amount),
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
                'invoices.subtotal_excl_tax', 'invoices.tax_total', DB::raw('invoices.total_incl_tax - COALESCE(credits.credited_amount, 0) as total_incl_tax'),
                DB::raw('COALESCE(paid.paid_amount, 0) as paid_amount'),
            ])
            ->orderBy('invoices.invoice_date')
            ->orderBy('invoices.id')
            ->cursor()
            ->map(fn ($row) => $this->toOutstandingRow($row));
    }

    /**
     * Allocation ledger as of a date, exposed under the alias `payment_allocations` so existing joins keep working.
     * An allocation counts from its effective date (NULL = the payment's own date: legacy rows and allocations made at receipt),
     * and a de-allocated one (payment_allocation_releases) still counts until the day it was released. Treasury (payment_date)
     * is never read here: a late allocation of an old advance moves the receivable, not the cash.
     */
    public function allocationLedger(string $date): \Illuminate\Database\Query\Builder
    {
        $live = DB::table('payment_allocations as pa')
            ->join('payments as pay', function ($join) {
                $join->on('pay.id', '=', 'pa.payment_id')->on('pay.organization_id', '=', 'pa.organization_id');
            })
            ->whereRaw('DATE(COALESCE(pa.effective_date, pay.payment_date)) <= ?', [$date])
            ->select('pa.organization_id', 'pa.payment_id', 'pa.sales_order_id', 'pa.amount')
            ->selectRaw('DATE(COALESCE(pa.effective_date, pay.payment_date)) as effective_date');
        $released = DB::table('payment_allocation_releases as rel')
            ->whereRaw('DATE(rel.effective_date) <= ?', [$date])->whereRaw('DATE(rel.released_date) > ?', [$date])
            ->select('rel.organization_id', 'rel.payment_id', 'rel.sales_order_id', 'rel.amount')
            ->selectRaw('DATE(rel.effective_date) as effective_date');

        return DB::query()->fromSub($live->unionAll($released), 'payment_allocations');
    }

    private function obligationsQuery(Organization $organization, Carbon $asOf, ?Store $store)
    {
        $collectionsSubquery = $this->allocationLedger($asOf->toDateString())
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.organization_id', $organization->getKey())
            ->where('payments.status', PaymentStatus::Posted->value)
            ->groupBy('payment_allocations.sales_order_id')
            ->select('payment_allocations.sales_order_id', DB::raw('SUM(payment_allocations.amount) as collected_amount'));

        $refundsSubquery = DB::table('payment_refunds')
            ->where('payment_refunds.organization_id', $organization->getKey())
            ->where('payment_refunds.status', 'posted')
            ->whereDate('payment_refunds.refund_date', '<=', $asOf->toDateString())
            ->groupBy('payment_refunds.sales_order_id')
            ->select('payment_refunds.sales_order_id', DB::raw('SUM(payment_refunds.amount) as refunded_amount'));

        $paidSubquery = DB::query()
            ->fromSub($collectionsSubquery, 'collections')
            ->leftJoinSub($refundsSubquery, 'refunds', 'refunds.sales_order_id', '=', 'collections.sales_order_id')
            ->select(
                'collections.sales_order_id',
                DB::raw('collections.collected_amount - COALESCE(refunds.refunded_amount, 0) as paid_amount'),
            );

        $creditsSubquery = DB::table('credit_notes')->whereNull('credit_notes.sandbox_id')
            ->where('credit_notes.organization_id', $organization->getKey())
            ->where('credit_notes.status', 'issued')
            ->whereDate('credit_notes.credit_note_date', '<=', $asOf->toDateString())
            ->groupBy('credit_notes.sales_order_id')
            ->select('credit_notes.sales_order_id', DB::raw('SUM(credit_notes.total_incl_tax) as credited_amount'));

        return DB::table('invoices')->whereNull('invoices.sandbox_id')
            ->leftJoinSub($paidSubquery, 'paid', 'paid.sales_order_id', '=', 'invoices.sales_order_id')
            // Credit Notes remain linked to the exact historical Invoice they
            // credited, while the current effective Invoice may be a later
            // version after a POS addendum/exchange. Aggregate the economic
            // effect at the stable SalesOrder boundary.
            ->leftJoinSub($creditsSubquery, 'credits', 'credits.sales_order_id', '=', 'invoices.sales_order_id')
            ->where('invoices.organization_id', $organization->getKey())
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereDate('invoices.invoice_date', '<=', $asOf->toDateString())
            ->when($store, fn ($query) => $query->where('invoices.store_id', $store->getKey()));
    }

    private function toOutstandingRow(object $row): array
    {
        $total = Decimal::normalize((string) $row->total_incl_tax);
        $paid = Decimal::normalize((string) $row->paid_amount);
        $outstanding = $this->nonNegative(Decimal::subtract($total, $paid));
        $status = $this->status($total, $paid);

        return [
            'id' => $row->id,
            'invoice_number' => $row->invoice_number,
            'invoice_date' => $row->invoice_date,
            'customer_name' => $row->customer_name,
            'customer_company' => $row->customer_company,
            'sales_order_id' => $row->sales_order_id,
            'store_id' => $row->store_id,
            'subtotal_excl_tax' => isset($row->subtotal_excl_tax) ? Decimal::normalize((string) $row->subtotal_excl_tax) : null,
            'tax_total' => isset($row->tax_total) ? Decimal::normalize((string) $row->tax_total) : null,
            'total_incl_tax' => $total,
            'paid_amount' => $paid,
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
