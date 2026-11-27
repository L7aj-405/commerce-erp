<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credit control at the ONE commercial boundary where a customer commitment is created: confirming a draft sales order.
 *
 * Default behaviour is UNCHANGED: enforcement is `off` for every organization until it is explicitly set to `warn` or
 * `block` (organizations.settings.customer_credit.enforcement). Even when on, nothing applies unless the customer has credit
 * enabled with a limit. Historical documents are never re-judged: only a FIRST confirmation of a draft is checked
 * (a commercial revision of an already confirmed order is not).
 *
 * Exposure after confirmation = receivable + unbilled + max(opening balance, 0) + this order's total
 * (the order is still a draft, so it is not yet inside `unbilled`: no double counting). Advances and pending instruments
 * are NOT deducted (conservative default, see CustomerFinancialPositionService).
 *
 * Concurrency: the customer's financial-terms row is locked FOR UPDATE inside the confirmation transaction, so two
 * simultaneous confirmations for one customer are serialized and the second sees the first one's commitment.
 *
 * Outcome when the limit would be exceeded:
 *   - an unconsumed, unexpired credit exception covering the excess (customer-wide or for this order) lets it through and is consumed;
 *   - else organization `warn`, or customer policy `warn` -> allowed, recorded in the audit log (event customer_credit.warning);
 *   - else (organization `block` AND customer policy `block`) -> refused with a validation error.
 * A customer whose financial status is `blocked` is refused whenever enforcement is not `off` (unless an exception covers the order).
 */
class CustomerCreditGuard
{
    public const ENFORCEMENT = ['off', 'warn', 'block'];

    /** Signed opening balances minus the advances currently applied to them (customer_opening_balance_settlements, not released). */
    private const OUTSTANDING_OPENING_SQL = 'ROUND(COALESCE(SUM(amount - COALESCE((SELECT SUM(s.amount) FROM customer_opening_balance_settlements s WHERE s.organization_id = customer_opening_balances.organization_id'
        .' AND s.opening_balance_id = customer_opening_balances.id AND s.released_date IS NULL), 0)), 0), 4)';

    public function __construct(private readonly FinanceReceivablesService $receivables, private readonly AuditLogger $audit) {}

    public static function enforcement(Organization $organization): string
    {
        $value = data_get($organization->settings, 'customer_credit.enforcement', 'off');

        return in_array($value, self::ENFORCEMENT, true) ? $value : 'off';
    }

    /** Customer payment term (days) when one is configured, so the order due date follows it; null = organization policy. */
    public function paymentTermDays(SalesOrder $order): ?int
    {
        if ($order->customer_id === null) {
            return null;
        }
        $days = DB::table('customer_financial_terms')->where('organization_id', $order->organization_id)->where('customer_id', $order->customer_id)->value('payment_term_days');

        return $days === null ? null : (int) $days;
    }

    /**
     * Serialize concurrent confirmations of one customer's orders. MUST be the first read of the confirmation transaction: under
     * InnoDB REPEATABLE READ the snapshot is fixed by the first plain SELECT, so a lock taken later would still read exposure from
     * a snapshot older than the commitments the lock holder just committed. A locking read always sees the latest committed data.
     */
    public function lockCustomer(SalesOrder $order): void
    {
        if ($order->customer_id !== null && $order->sandbox_id === null) {
            DB::table('customer_financial_terms')->where('organization_id', $order->organization_id)->where('customer_id', $order->customer_id)->lockForUpdate()->first();
        }
    }

    /** Optional policy: count verified, available advances (posted customer payments not allocated yet) against exposure. Default false. */
    public static function deductsAdvances(Organization $organization): bool
    {
        return (bool) data_get($organization->settings, 'customer_credit.deduct_advances', false);
    }

    /**
     * Commitment-increasing workflows other than first confirmation. `$increase` is the amount the order's total grows by.
     * The order is already confirmed (so already inside the exposure): exposure + increase is compared with the limit.
     * Never called for a reduction: a decrease is not a new commitment and is never blocked.
     */
    public function assertCanIncrease(User $actor, SalesOrder $order, string $increase, string $context): void
    {
        if (Decimal::compare($increase, '0') <= 0) {
            return;
        }
        $this->lockCustomer($order);
        $this->evaluate($actor, $order, $increase, $context);
    }

    public function assertCanConfirm(User $actor, SalesOrder $order): void
    {
        if ($order->confirmed_at !== null) {
            return;
        }
        $revision = $order->currentRevision;
        if ($revision && $revision->status === 'in_progress') {
            // Commercial correction re-confirmation: only a GROWTH of the previously committed total is a new commitment.
            $before = (string) data_get($revision->before_snapshot, 'totals.total_incl_tax', '');
            if ($before !== '' && Decimal::compare((string) $order->total_incl_tax, $before) <= 0) {
                return;
            }
        }
        $this->evaluate($actor, $order, (string) $order->total_incl_tax, 'confirmation');
    }

    /** `$amount` is what the exposure grows by: the order is a draft (not in the exposure yet) or already confirmed (only the increase is new). */
    private function evaluate(User $actor, SalesOrder $order, string $amount, string $context): void
    {
        $organization = $order->organization;
        $enforcement = self::enforcement($organization);
        if ($enforcement === 'off' || $order->customer_id === null || $order->sandbox_id !== null) {
            return;
        }
        $terms = DB::table('customer_financial_terms')->where('organization_id', $order->organization_id)->where('customer_id', $order->customer_id)->lockForUpdate()->first();
        if ($terms === null) {
            return;
        }
        $blockedStatus = $terms->financial_status === 'blocked';
        $limited = (bool) $terms->credit_enabled && $terms->credit_limit !== null;
        if (! $blockedStatus && ! $limited) {
            return;
        }

        $info = $this->exposureInfo($organization, (int) $order->customer_id, (string) $order->currency_code);
        if (! $info['comparable']) {
            // Never compare incompatible currencies silently: the limit is expressed in one currency.
            $this->audit->record('customer_credit.currency_not_comparable', $actor, $organization, $order->store, $order, newValues: [
                'customer_id' => $order->customer_id, 'order_currency' => $order->currency_code, 'exposure_currencies' => $info['currencies'], 'context' => $context,
            ]);
            if (! $blockedStatus) {
                if ($enforcement === 'block' && $terms->policy === 'block') {
                    logger()->notice('customer_credit.blocked', ['organization_id' => $order->organization_id, 'customer_id' => $order->customer_id, 'order_id' => $order->getKey(), 'reason' => 'currency_not_comparable']);
                    throw ValidationException::withMessages(['order' => 'Le plafond de crédit ne peut pas être évalué : le client a des engagements dans plusieurs devises.']);
                }

                return;
            }
            $info['exposure'] = '0.0000';
        }
        $after = Decimal::add($info['exposure'], $amount);
        $after = Decimal::compare($after, '0') < 0 ? '0.0000' : $after;
        $excess = $limited ? Decimal::subtract($after, (string) $terms->credit_limit) : '0.0000';
        $exceeds = $limited && Decimal::compare($excess, '0') > 0;
        if (! $blockedStatus && ! $exceeds) {
            return;
        }

        $exception = DB::table('customer_credit_exceptions')->where('organization_id', $order->organization_id)->where('customer_id', $order->customer_id)
            ->whereNull('consumed_at')->where(fn ($q) => $q->whereNull('sales_order_id')->orWhere('sales_order_id', $order->getKey()))
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', Carbon::now()->toDateString()))
            ->orderBy('id')->lockForUpdate()->get()
            ->first(fn ($row) => $blockedStatus ? true : Decimal::compare((string) $row->authorized_amount, $excess) >= 0);
        if ($exception !== null) {
            DB::table('customer_credit_exceptions')->where('id', $exception->id)->update(['consumed_at' => now(), 'sales_order_id' => $order->getKey(), 'updated_at' => now()]);
            $this->audit->record('customer_credit.exception_used', $actor, $organization, $order->store, $order, newValues: [
                'exception_id' => $exception->id, 'customer_id' => $order->customer_id, 'excess' => $excess, 'reason' => $exception->reason,
            ]);

            return;
        }

        $blocks = $enforcement === 'block' && ($blockedStatus || $terms->policy === 'block');
        if (! $blocks) {
            $this->audit->record('customer_credit.warning', $actor, $organization, $order->store, $order, newValues: [
                'customer_id' => $order->customer_id, 'credit_limit' => $terms->credit_limit, 'exposure_after' => $after, 'excess' => $excess, 'financial_status' => $terms->financial_status,
            ]);
        }
        if ($blocks) {
            // Not audited here: the refusal rolls back the confirmation transaction (and any audit row with it).
            logger()->notice('customer_credit.blocked', ['organization_id' => $order->organization_id, 'customer_id' => $order->customer_id, 'order_id' => $order->getKey(), 'excess' => $excess]);
            throw ValidationException::withMessages(['order' => $blockedStatus
                ? 'Ce client est bloqué financièrement : la commande ne peut pas être confirmée sans exception de crédit autorisée.'
                : 'Le plafond de crédit du client serait dépassé : la commande ne peut pas être confirmée sans exception de crédit autorisée.']);
        }
        session()->flash('credit_warning', 'Attention : le plafond de crédit de ce client est dépassé.');
    }

    /** Current exposure (organization-wide, every store): receivable + unbilled + max(opening, 0). */
    public function exposureExcluding(Organization $organization, int $customerId): string
    {
        return $this->exposureInfo($organization, $customerId, null)['exposure'];
    }

    /**
     * Exposure in ONE currency. `comparable` is false when the customer has commitments (documents or opening balances) in more than
     * one currency, or in a currency other than `$currency`: the figure is then meaningless against a single limit and is never used silently.
     *
     * @return array{exposure:string, comparable:bool, currencies:list<string>}
     */
    public function exposureInfo(Organization $organization, int $customerId, ?string $currency): array
    {
        $storeIds = DB::table('stores')->where('organization_id', $organization->getKey())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $position = $this->receivables->customerPositionsAsOf($organization, [$customerId], $storeIds)[$customerId] ?? null;
        $currencies = $position && ! $position['mixed_currency'] && $position['currency_code'] ? [(string) $position['currency_code']] : [];
        $mixed = (bool) ($position['mixed_currency'] ?? false);
        $openings = DB::table('customer_opening_balances')->where('organization_id', $organization->getKey())->where('customer_id', $customerId)->where('status', 'posted')
            ->groupBy('currency_code')->select('currency_code')->selectRaw(self::OUTSTANDING_OPENING_SQL.' as total')->get();
        $opening = '0.0000';
        foreach ($openings as $row) {
            $total = Decimal::normalize((string) $row->total);
            if (Decimal::compare($total, '0') === 0) {
                continue;
            }
            $currencies[] = (string) $row->currency_code;
            if ($currency === null || $row->currency_code === $currency) {
                $opening = Decimal::compare($total, '0') > 0 ? $total : '0.0000';
            }
        }
        $currencies = array_values(array_unique($currencies));
        $comparable = ! $mixed && count($currencies) <= 1 && ($currency === null || $currencies === [] || $currencies[0] === $currency);
        $exposure = Decimal::add(Decimal::add((string) ($position['receivable'] ?? '0'), (string) ($position['unbilled'] ?? '0')), $opening);
        if ($comparable && self::deductsAdvances($organization)) {
            $payments = DB::table('payments as p')->where('p.organization_id', $organization->getKey())->where('p.customer_id', $customerId)->where('p.status', 'posted')
                ->when($currency, fn ($q) => $q->where('p.currency_code', $currency));
            $received = Decimal::normalize((string) (clone $payments)->sum('p.amount'));
            $allocated = Decimal::normalize((string) (clone $payments)->join('payment_allocations as a', 'a.payment_id', '=', 'p.id')->sum('a.amount'));
            $allocated = Decimal::add($allocated, Decimal::normalize((string) (clone $payments)->join('customer_opening_balance_settlements as s', 's.payment_id', '=', 'p.id')
                ->whereNull('s.released_date')->sum('s.amount')));
            $advances = Decimal::subtract($received, $allocated);
            // Verified money only: a pending cheque/effect has no posted payment yet, so it can never be deducted here.
            $exposure = Decimal::subtract($exposure, Decimal::compare($advances, '0') > 0 ? $advances : '0.0000');
        }

        return ['exposure' => $exposure, 'comparable' => $comparable, 'currencies' => $currencies];
    }

    /**
     * Bulk org-wide exposure for a set of customers (credit control pages; same formula as exposureExcluding()).
     *
     * @param  list<int>  $customerIds
     * @return array<int, array{currency_code:?string, receivable:string, unbilled:string, overdue:string, opening:string, exposure:string, mixed_currency:bool}>
     */
    public function exposures(Organization $organization, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $storeIds = DB::table('stores')->where('organization_id', $organization->getKey())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $positions = $this->receivables->customerPositionsAsOf($organization, $customerIds, $storeIds);
        $openings = DB::table('customer_opening_balances')->where('organization_id', $organization->getKey())->where('status', 'posted')->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')->select('customer_id')->selectRaw(self::OUTSTANDING_OPENING_SQL.' as total')->pluck('total', 'customer_id');
        $out = [];
        foreach ($customerIds as $id) {
            $p = $positions[$id] ?? null;
            $opening = Decimal::normalize((string) ($openings[$id] ?? '0'));
            $positiveOpening = Decimal::compare($opening, '0') > 0 ? $opening : '0.0000';
            $mixed = (bool) ($p['mixed_currency'] ?? false);
            $receivable = $mixed ? '0.0000' : (string) ($p['receivable'] ?? '0.0000');
            $unbilled = $mixed ? '0.0000' : (string) ($p['unbilled'] ?? '0.0000');
            $out[$id] = ['currency_code' => $p['currency_code'] ?? null, 'receivable' => $receivable, 'unbilled' => $unbilled,
                'overdue' => $mixed ? '0.0000' : Decimal::add((string) ($p['overdue'] ?? '0'), (string) ($p['unbilled_overdue'] ?? '0')), 'opening' => $opening,
                'exposure' => Decimal::add(Decimal::add($receivable, $unbilled), $positiveOpening), 'mixed_currency' => $mixed];
        }

        return $out;
    }
}
