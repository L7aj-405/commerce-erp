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

    public function assertCanConfirm(User $actor, SalesOrder $order): void
    {
        $organization = $order->organization;
        $enforcement = self::enforcement($organization);
        if ($enforcement === 'off' || $order->customer_id === null || $order->sandbox_id !== null || $order->confirmed_at !== null) {
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

        $exposure = $this->exposureExcluding($organization, (int) $order->customer_id);
        $after = Decimal::add($exposure, (string) $order->total_incl_tax);
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
        $storeIds = DB::table('stores')->where('organization_id', $organization->getKey())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $position = $this->receivables->customerPositionsAsOf($organization, [$customerId], $storeIds)[$customerId] ?? null;
        $opening = (string) DB::table('customer_opening_balances')->where('organization_id', $organization->getKey())->where('customer_id', $customerId)->where('status', 'posted')->sum('amount');
        $opening = Decimal::compare(Decimal::normalize($opening), '0') > 0 ? Decimal::normalize($opening) : '0.0000';

        return Decimal::add(Decimal::add((string) ($position['receivable'] ?? '0'), (string) ($position['unbilled'] ?? '0')), $opening);
    }
}
