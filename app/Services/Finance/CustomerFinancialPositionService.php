<?php

namespace App\Services\Finance;

use App\Enums\SalesOrderStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DocumentVisibility;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer financial position for MANY customers at once (Finance clients). Bounded query count: the number of
 * queries does not depend on the number of customers.
 *
 * Each concept stays separate (never merged silently):
 *
 *  - invoiced_gross / credited / net_invoiced / paid : issued invoices, the credit notes and net collections of THEIR OWN
 *      orders (order-specific, never pooled across orders). Source: FinanceReceivablesService::customerPositionsAsOf().
 *  - receivable : sum per issued invoice of max(net invoice - net collections, 0), settlement precision.
 *  - overdue / undated / not_due / bands : receivable split on invoices.due_date against the overdue basis date. An invoice
 *      with no due date is "undated" - a due date is never invented.
 *  - unbilled : remaining balance of confirmed orders WITHOUT an issued invoice (a commercial exposure, not a receivable).
 *  - overcollected : money held above the net invoice = refund obligation owed TO the customer.
 *  - opening_balance : signed sum of POSTED customer opening balances effective on/before the date. It is neither revenue
 *      nor cash: no sale, VAT, invoice or bank receipt is created.
 *  - advances_available : posted customer advances (payments with customer_id) minus what was allocated to orders.
 *  - pending_instruments : incoming cheques/effects not yet cleared (received, in portfolio, submitted). Not cash.
 *  - credit_exposure = receivable + unbilled + max(opening_balance, 0).
 *      Advances and pending instruments are NOT deducted (conservative default: they are not settled money until applied
 *      or cleared); a negative opening balance (money owed to the customer) never offsets exposure.
 *      available_credit = credit_limit - credit_exposure (negative = over limit), only when credit is enabled with a limit.
 *
 * Viewer scope (same rules as CustomerFinancialSummary): figures use only the documents the viewer may see (DocumentVisibility:
 * view permission + visible stores). A figure the viewer has no permission for is null, never a zero.
 */
final class CustomerFinancialPositionService
{
    private const PENDING_INSTRUMENT_STATES = ['received', 'in_portfolio', 'submitted'];

    public function __construct(
        private readonly DocumentVisibility $visibility,
        private readonly FinanceReceivablesService $receivables,
    ) {}

    /**
     * @param  list<int>  $customerIds
     * @return array<int, array<string, mixed>> one entry per requested customer id
     */
    public function forCustomers(Organization $organization, array $customerIds, User $viewer, ?Carbon $asOf = null, ?Carbon $today = null): array
    {
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));
        if ($customerIds === []) {
            return [];
        }
        $organizationId = (int) $organization->getKey();
        $stores = $this->visibility->storeIds($viewer, $organization) ?: [0];
        $canInvoices = $this->visibility->canView($viewer, $organization, Invoice::class);
        $canPayments = $this->visibility->canView($viewer, $organization, Payment::class);
        $canOrders = $this->visibility->canView($viewer, $organization, SalesOrder::class);
        $dateLimit = ($asOf ?? Carbon::parse('2999-12-31'))->toDateString();

        $positions = $this->receivables->customerPositionsAsOf($organization, $customerIds, $stores, $asOf, $today);

        $openings = DB::table('customer_opening_balances')->where('organization_id', $organizationId)->where('status', 'posted')
            ->whereIn('customer_id', $customerIds)->whereDate('effective_date', '<=', $dateLimit)
            ->groupBy('customer_id', 'currency_code')->select('customer_id', 'currency_code')
            // Outstanding opening balance: the signed line minus the advances applied to it (and not released) as of the date.
            ->selectRaw('ROUND(COALESCE(SUM(amount - COALESCE((SELECT SUM(s.amount) FROM customer_opening_balance_settlements s WHERE s.organization_id = customer_opening_balances.organization_id'
                .' AND s.opening_balance_id = customer_opening_balances.id AND DATE(s.effective_date) <= ? AND (s.released_date IS NULL OR DATE(s.released_date) > ?)), 0)), 0), 4) as total', [$dateLimit, $dateLimit])
            ->get()->groupBy('customer_id');

        $allocated = DB::table('payment_allocations')->where('organization_id', $organizationId)->groupBy('payment_id')
            ->select('payment_id')->selectRaw('SUM(amount) as allocated');
        $advances = $canPayments ? DB::table('payments as p')->leftJoinSub($allocated, 'a', 'a.payment_id', '=', 'p.id')
            ->where('p.organization_id', $organizationId)->where('p.status', 'posted')->whereNotNull('p.customer_id')
            ->whereIn('p.customer_id', $customerIds)->whereIn('p.store_id', $stores)
            ->groupBy('p.customer_id', 'p.currency_code')->select('p.customer_id', 'p.currency_code')
            ->selectRaw('ROUND(COALESCE(SUM(p.amount - COALESCE(a.allocated, 0) - COALESCE((SELECT SUM(s.amount) FROM customer_opening_balance_settlements s WHERE s.organization_id = p.organization_id'
                .' AND s.payment_id = p.id AND s.released_date IS NULL), 0)), 0), 4) as total')->get()->groupBy('customer_id') : collect();

        // OPT-FC-002: net sales (every confirmed order net of received returns, invoiced or not) and net collections.
        $returns = DB::table('customer_returns')->where('organization_id', $organizationId)->where('status', 'received')
            ->when($asOf !== null, fn ($q) => $q->where('received_at', '<', SalesOrderPaymentCalculator::endOfLocalDayUtc($organizationId, $asOf)))
            ->groupBy('sales_order_id')->select('sales_order_id')->selectRaw('SUM(total_incl_tax) as returned');
        $netSales = $canOrders ? DB::table('sales_orders as o')->leftJoinSub($returns, 'r', 'r.sales_order_id', '=', 'o.id')
            ->where('o.organization_id', $organizationId)->whereNull('o.sandbox_id')->where('o.status', SalesOrderStatus::Confirmed->value)
            ->whereIn('o.customer_id', $customerIds)->whereIn('o.store_id', $stores)->whereDate('o.sale_date', '<=', $dateLimit)
            ->groupBy('o.customer_id', 'o.currency_code')->select('o.customer_id', 'o.currency_code')
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN o.total_incl_tax - COALESCE(r.returned, 0) > 0 THEN o.total_incl_tax - COALESCE(r.returned, 0) ELSE 0 END), 0), 4) as total')
            ->get()->groupBy('customer_id') : collect();
        // Net collections = posted cash received (reversed payments excluded) minus executed refunds. A customer advance counts once, when
        // received (its later allocation to an order is NOT a new collection); an order payment counts through its allocations.
        $advanceCash = DB::table('payments as p')->where('p.organization_id', $organizationId)->where('p.status', 'posted')->whereNotNull('p.customer_id')
            ->whereIn('p.customer_id', $customerIds)->whereIn('p.store_id', $stores)->whereDate('p.payment_date', '<=', $dateLimit)
            ->groupBy('p.customer_id', 'p.currency_code')->select('p.customer_id', 'p.currency_code')->selectRaw('ROUND(COALESCE(SUM(p.amount), 0), 4) as total')->get();
        $orderCash = DB::table('payment_allocations as pa')->join('payments as p', function ($j) {
            $j->on('p.id', '=', 'pa.payment_id')->on('p.organization_id', '=', 'pa.organization_id');
        })->join('sales_orders as o', function ($j) {
            $j->on('o.id', '=', 'pa.sales_order_id')->on('o.organization_id', '=', 'pa.organization_id');
        })->where('pa.organization_id', $organizationId)->where('p.status', 'posted')->whereNull('p.customer_id')->whereNull('o.sandbox_id')
            ->whereIn('o.customer_id', $customerIds)->whereIn('o.store_id', $stores)->whereDate('p.payment_date', '<=', $dateLimit)
            ->groupBy('o.customer_id', 'o.currency_code')->select('o.customer_id', 'o.currency_code')->selectRaw('ROUND(COALESCE(SUM(pa.amount), 0), 4) as total')->get();
        $refunded = DB::table('payment_refunds as rf')->join('sales_orders as o', function ($j) {
            $j->on('o.id', '=', 'rf.sales_order_id')->on('o.organization_id', '=', 'rf.organization_id');
        })->where('rf.organization_id', $organizationId)->where('rf.status', 'posted')->whereNull('o.sandbox_id')
            ->whereIn('o.customer_id', $customerIds)->whereIn('o.store_id', $stores)->whereDate('rf.refund_date', '<=', $dateLimit)
            ->groupBy('o.customer_id', 'o.currency_code')->select('o.customer_id', 'o.currency_code')->selectRaw('ROUND(COALESCE(SUM(rf.amount), 0), 4) as total')->get();
        $cash = ['advance' => $advanceCash->groupBy('customer_id'), 'order' => $orderCash->groupBy('customer_id'), 'refund' => $refunded->groupBy('customer_id')];

        $instruments = DB::table('customer_payment_instruments')->where('organization_id', $organizationId)
            ->whereIn('state', self::PENDING_INSTRUMENT_STATES)->whereIn('customer_id', $customerIds)->whereIn('store_id', $stores)
            ->groupBy('customer_id', 'currency_code')->select('customer_id', 'currency_code')
            ->selectRaw('ROUND(COALESCE(SUM(amount), 0), 4) as total')->get()->groupBy('customer_id');

        $terms = DB::table('customer_financial_terms')->where('organization_id', $organizationId)->whereIn('customer_id', $customerIds)->get()->keyBy('customer_id');

        $out = [];
        foreach ($customerIds as $id) {
            $position = $positions[$id] ?? null;
            $currency = $position['currency_code'] ?? null;
            $single = fn ($rows) => $this->singleCurrencyTotal($rows[$id] ?? collect(), $currency);

            $figure = fn (string $key, bool $allowed) => $allowed && $position !== null && ! $position['mixed_currency'] ? $position[$key] : null;
            $zeroIfNoDocuments = fn (string $key, bool $allowed) => $allowed ? ($position === null ? '0.0000' : $figure($key, true)) : null;
            $canReceivable = $canInvoices && $canPayments;

            $receivable = $zeroIfNoDocuments('receivable', $canReceivable);
            $unbilled = $zeroIfNoDocuments('unbilled', $canOrders && $canPayments);
            $opening = $this->singleCurrencyTotal($openings[$id] ?? collect(), $currency);
            $term = $terms[$id] ?? null;
            $salesTotal = $canOrders ? $single($netSales) : null;
            $collected = null;
            if ($canOrders && $canPayments) {
                $parts = array_map(fn ($rows) => $this->singleCurrencyTotal($rows[$id] ?? collect(), $currency), $cash);
                $collected = in_array(false, $parts, true) ? false
                    : Decimal::subtract(Decimal::add((string) $parts['advance'], (string) $parts['order']), (string) $parts['refund']);
            }
            $limit = $term && $term->credit_enabled && $term->credit_limit !== null ? Decimal::normalize((string) $term->credit_limit) : null;
            $exposure = ($receivable !== null && $unbilled !== null && $opening !== false)
                ? Decimal::add(Decimal::add($receivable, $unbilled), Decimal::compare((string) $opening, '0') > 0 ? (string) $opening : '0.0000') : null;
            $available = ($limit !== null && $exposure !== null) ? Decimal::subtract($limit, $exposure) : null;
            $overdueInvoices = $zeroIfNoDocuments('overdue', $canReceivable);
            $overdueOrders = $zeroIfNoDocuments('unbilled_overdue', $canOrders && $canPayments);
            // Commercial overdue = overdue invoice receivables + overdue unbilled order balances (disjoint buckets).
            $overdue = ($overdueInvoices !== null && $overdueOrders !== null) ? Decimal::add($overdueInvoices, $overdueOrders) : null;

            $out[$id] = [
                'currency_code' => $currency,
                'mixed_currency' => (bool) ($position['mixed_currency'] ?? false),
                'invoiced_gross' => $zeroIfNoDocuments('invoiced_gross', $canInvoices),
                'credited' => $zeroIfNoDocuments('credited', $canInvoices),
                'net_invoiced' => $zeroIfNoDocuments('net_invoiced', $canInvoices),
                'paid' => $zeroIfNoDocuments('paid', $canReceivable),
                'receivable' => $receivable,
                'overdue_invoices' => $overdueInvoices,
                'overdue_unbilled' => $overdueOrders,
                'overdue' => $overdue,
                'unbilled_bands' => ($canOrders && $canPayments) ? [
                    'not_due' => $zeroIfNoDocuments('unbilled_not_due', true), '1_30' => $zeroIfNoDocuments('unbilled_band_1_30', true), '31_60' => $zeroIfNoDocuments('unbilled_band_31_60', true),
                    '61_90' => $zeroIfNoDocuments('unbilled_band_61_90', true), '90_plus' => $zeroIfNoDocuments('unbilled_band_90_plus', true), 'undated' => $zeroIfNoDocuments('unbilled_undated', true),
                ] : null,
                'undated' => $zeroIfNoDocuments('undated', $canReceivable),
                'not_due' => $zeroIfNoDocuments('not_due', $canReceivable),
                'bands' => $canReceivable ? [
                    'not_due' => $zeroIfNoDocuments('not_due', true), '1_30' => $zeroIfNoDocuments('band_1_30', true), '31_60' => $zeroIfNoDocuments('band_31_60', true),
                    '61_90' => $zeroIfNoDocuments('band_61_90', true), '90_plus' => $zeroIfNoDocuments('band_90_plus', true), 'undated' => $zeroIfNoDocuments('undated', true),
                ] : null,
                'unbilled' => $unbilled,
                'refund_obligation' => $zeroIfNoDocuments('overcollected', $canReceivable),
                'opening_balance' => $opening === false ? null : $opening,
                'advances_available' => $canPayments ? ($single($advances) === false ? null : $single($advances)) : null,
                'pending_instruments' => $single($instruments) === false ? null : $single($instruments),
                'credit_enabled' => (bool) ($term->credit_enabled ?? false),
                'credit_limit' => $limit,
                'net_sales' => $salesTotal === false || $salesTotal === null || ($position['mixed_currency'] ?? false) ? null : $salesTotal,
                'net_collected' => $collected === false || $collected === null || ($position['mixed_currency'] ?? false) ? null : $collected,
                'credit_exposure' => $exposure,
                // OPT-FC-001/002: the authoritative "Total à encaisser" (same figure as the credit exposure): invoiced receivable + unbilled exposure + remaining opening-balance debt, no double counting.
                'total_to_collect' => $exposure,
                'available_credit' => $available,
                'payment_term_days' => $term->payment_term_days ?? null,
                'financial_status' => $this->status($term, $overdue, $available),
            ];
        }

        return $out;
    }

    /** @return string|false  '0.0000' when no row; false when several currencies (or a currency different from the position's) are mixed */
    private function singleCurrencyTotal($rows, ?string $currency): string|false
    {
        if ($rows->isEmpty()) {
            return '0.0000';
        }
        if ($rows->count() > 1 || ($currency !== null && strtoupper((string) $rows->first()->currency_code) !== strtoupper($currency))) {
            return false;
        }

        return Decimal::normalize((string) $rows->first()->total);
    }

    private function status(?object $term, ?string $overdue, ?string $available): string
    {
        if ($term && $term->financial_status === 'blocked') {
            return 'blocked';
        }
        if ($available !== null && Decimal::compare($available, '0') < 0) {
            return 'over_limit';
        }
        if ($overdue !== null && Decimal::compare($overdue, '0') > 0) {
            return 'overdue';
        }

        return $term && $term->financial_status === 'watch' ? 'watch' : 'normal';
    }
}
