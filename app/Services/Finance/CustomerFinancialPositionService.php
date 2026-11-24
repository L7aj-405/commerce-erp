<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DocumentVisibility;
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
            ->selectRaw('ROUND(COALESCE(SUM(amount), 0), 4) as total')->get()->groupBy('customer_id');

        $allocated = DB::table('payment_allocations')->where('organization_id', $organizationId)->groupBy('payment_id')
            ->select('payment_id')->selectRaw('SUM(amount) as allocated');
        $advances = $canPayments ? DB::table('payments as p')->leftJoinSub($allocated, 'a', 'a.payment_id', '=', 'p.id')
            ->where('p.organization_id', $organizationId)->where('p.status', 'posted')->whereNotNull('p.customer_id')
            ->whereIn('p.customer_id', $customerIds)->whereIn('p.store_id', $stores)
            ->groupBy('p.customer_id', 'p.currency_code')->select('p.customer_id', 'p.currency_code')
            ->selectRaw('ROUND(COALESCE(SUM(p.amount - COALESCE(a.allocated, 0)), 0), 4) as total')->get()->groupBy('customer_id') : collect();

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
                'credit_exposure' => $exposure,
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
