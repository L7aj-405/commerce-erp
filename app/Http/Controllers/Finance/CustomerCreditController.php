<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerCreditGuard;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Sandbox\SandboxContext;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FC-06 Crédit client: organization credit-control policy, customer exposure vs limit, blocked customers and authorized
 * exceptions. Reads need finance.view; changing the policy needs finance.customers.terms.manage; granting an exception needs
 * finance.customers.credit_override. Exposure is the ORGANIZATION-wide figure used by the credit check itself
 * (CustomerCreditGuard), not a viewer-scoped one: credit control must see every store's commitments.
 */
class CustomerCreditController extends Controller
{
    private const FILTERS = ['all', 'limits', 'exposure', 'overruns', 'blocked', 'watch', 'overdue'];

    private const BATCH = 500;

    private const PER_PAGE = 50;

    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, CustomerCreditGuard $credit): Response
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        // Server-side register (no customer cap): every followed customer is evaluated in batches, the chosen population filter is applied to the
        // WHOLE set, then the filtered result is paginated. Counts per population are computed on the same evaluated set.
        $requested = $request->query('filter', $request->query('section'));   // legacy ?section=limits|exposure|overruns|blocked bookmarks still select their population
        $filter = in_array($requested, self::FILTERS, true) ? $requested : 'all';
        $rows = [];
        DB::table('customer_financial_terms as t')->join('customers as c', 'c.id', '=', 't.customer_id')->where('t.organization_id', $organization->getKey())
            ->where(fn ($q) => $q->where('t.credit_enabled', true)->orWhere('t.financial_status', '!=', 'normal')->orWhereNotNull('t.payment_term_days'))
            ->orderBy('c.display_name')->orderBy('t.customer_id')
            ->select(['t.customer_id', 'c.display_name', 't.credit_enabled', 't.credit_limit', 't.payment_term_days', 't.min_deposit_percent', 't.overdue_tolerance_days', 't.policy', 't.financial_status'])
            ->chunk(self::BATCH, function ($terms) use ($organization, $credit, &$rows) {
                $exposures = $credit->exposures($organization, $terms->pluck('customer_id')->map(fn ($id) => (int) $id)->all());
                foreach ($terms as $t) {
                    $e = $exposures[$t->customer_id];
                    $limit = $t->credit_enabled && $t->credit_limit !== null ? Decimal::normalize((string) $t->credit_limit) : null;
                    $rows[] = ['customer_id' => (int) $t->customer_id, 'customer' => $t->display_name, 'credit_enabled' => (bool) $t->credit_enabled, 'credit_limit' => $limit,
                        'payment_term_days' => $t->payment_term_days, 'policy' => $t->policy, 'financial_status' => $t->financial_status, 'currency_code' => $e['currency_code'],
                        'exposure' => $e['exposure'], 'overdue' => $e['overdue'], 'available' => $limit !== null ? Decimal::subtract($limit, $e['exposure']) : null, 'mixed_currency' => $e['mixed_currency']];
                }
            });
        $rows = collect($rows);
        $counts = collect(self::FILTERS)->mapWithKeys(fn ($f) => [$f => $rows->filter(fn ($r) => $this->matches($f, $r))->count()])->all();
        $filtered = $rows->filter(fn ($r) => $this->matches($filter, $r))->values();
        $pages = max(1, intdiv($filtered->count() + self::PER_PAGE - 1, self::PER_PAGE));
        $page = min(max(1, (int) $request->query('page', 1)), $pages);
        $rows = $filtered->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();
        $exceptions = DB::table('customer_credit_exceptions as x')->join('customers as c', 'c.id', '=', 'x.customer_id')->where('x.organization_id', $organization->getKey())
            ->orderByDesc('x.id')->limit(100)->get(['x.id', 'x.customer_id', 'c.display_name', 'x.authorized_amount', 'x.reason', 'x.expires_on', 'x.consumed_at', 'x.sales_order_id', 'x.created_at']);

        return Inertia::render('Finance/Clients/Credit', [
            'organization' => $organization->only(['id', 'name']), 'enforcement' => CustomerCreditGuard::enforcement($organization), 'deductAdvances' => CustomerCreditGuard::deductsAdvances($organization), 'rows' => $rows, 'filter' => $filter, 'counts' => $counts, 'meta' => ['total' => $filtered->count(), 'page' => $page, 'pages' => $pages], 'exceptions' => $exceptions,
            'businessDate' => BusinessTime::today($organization),
            'customers' => $request->user()->hasPermission($organization, 'finance.customers.credit_override') ? Customer::query()->where('organization_id', $organization->getKey())->orderBy('display_name')->limit(1000)->get(['id', 'display_name']) : [],
            'can' => ['policy' => $request->user()->hasPermission($organization, 'finance.customers.terms.manage'), 'override' => $request->user()->hasPermission($organization, 'finance.customers.credit_override')],
        ]);
    }

    /**
     * Protected destination of the GLOBAL credit policy (moved out of the risk register). Same permission as the existing save endpoint:
     * being able to see credit risks never grants access to the organization-wide settings.
     */
    public function policy(Request $request, ActiveTenantContext $context): Response
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'finance.customers.terms.manage'), 403);

        return Inertia::render('Settings/CustomerCreditPolicy', ['organization' => $organization->only(['id', 'name']), 'enforcement' => CustomerCreditGuard::enforcement($organization), 'deductAdvances' => CustomerCreditGuard::deductsAdvances($organization)]);
    }

    public function savePolicy(Request $request, ActiveTenantContext $context, AuditLogger $audit): RedirectResponse
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'finance.customers.terms.manage'), 403);
        $data = $request->validate(['enforcement' => ['required', Rule::in(CustomerCreditGuard::ENFORCEMENT)], 'deduct_advances' => ['sometimes', 'boolean']]);
        $old = ['enforcement' => CustomerCreditGuard::enforcement($organization), 'deduct_advances' => CustomerCreditGuard::deductsAdvances($organization)];
        $new = ['enforcement' => $data['enforcement'], 'deduct_advances' => (bool) ($data['deduct_advances'] ?? $old['deduct_advances'])];
        $organization->settings = array_replace_recursive($organization->settings ?? [], ['customer_credit' => $new]);
        $organization->save();
        $audit->record('customer_credit.policy_changed', $request->user(), $organization, null, $organization, oldValues: $old, newValues: $new);

        return back()->with('success', 'Politique de crédit enregistrée.');
    }

    public function grantException(Request $request, ActiveTenantContext $context, AuditLogger $audit): RedirectResponse
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'finance.customers.credit_override'), 403);
        $data = $request->validate(['customer_id' => ['required', 'integer'], 'authorized_amount' => ['required', 'decimal:0,4'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $customer = Customer::query()->where('organization_id', $organization->getKey())->findOrFail($data['customer_id']);
        $amount = Decimal::positive($data['authorized_amount'], 'authorized_amount');
        $id = DB::table('customer_credit_exceptions')->insertGetId(['organization_id' => $organization->getKey(), 'customer_id' => $customer->getKey(), 'authorized_amount' => $amount,
            'reason' => trim($data['reason']), 'authorized_by_user_id' => $request->user()->getKey(), 'expires_on' => $data['expires_on'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $audit->record('customer_credit.exception_granted', $request->user(), $organization, null, $customer, newValues: ['exception_id' => $id, 'authorized_amount' => $amount, 'reason' => trim($data['reason']), 'expires_on' => $data['expires_on'] ?? null]);

        return back()->with('success', 'Exception de crédit autorisée.');
    }

    /** One population filter, evaluated on the full (non-paginated) row set. @param array<string, mixed> $r */
    private function matches(string $filter, array $r): bool
    {
        $positive = fn (?string $v) => $v !== null && Decimal::compare($v, '0') > 0;

        return match ($filter) {
            'limits' => $r['credit_enabled'],
            'exposure' => ! $r['mixed_currency'] && $positive($r['exposure']),
            'overruns' => $r['available'] !== null && Decimal::compare($r['available'], '0') < 0,
            'blocked' => $r['financial_status'] === 'blocked',
            'watch' => $r['financial_status'] === 'watch',
            'overdue' => ! $r['mixed_currency'] && $positive($r['overdue']),
            default => true,
        };
    }
}
