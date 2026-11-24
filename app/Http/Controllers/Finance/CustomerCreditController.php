<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerCreditGuard;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Sandbox\SandboxContext;
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
    private const CAP = 500;

    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, CustomerCreditGuard $credit): Response
    {
        abort_if(app(SandboxContext::class)->isRestricted(), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $terms = DB::table('customer_financial_terms as t')->join('customers as c', 'c.id', '=', 't.customer_id')->where('t.organization_id', $organization->getKey())
            ->where(fn ($q) => $q->where('t.credit_enabled', true)->orWhere('t.financial_status', '!=', 'normal')->orWhereNotNull('t.payment_term_days'))
            ->orderBy('c.display_name')->limit(self::CAP)
            ->get(['t.customer_id', 'c.display_name', 't.credit_enabled', 't.credit_limit', 't.payment_term_days', 't.min_deposit_percent', 't.overdue_tolerance_days', 't.policy', 't.financial_status']);
        $exposures = $credit->exposures($organization, $terms->pluck('customer_id')->map(fn ($id) => (int) $id)->all());
        $rows = $terms->map(function ($t) use ($exposures) {
            $e = $exposures[$t->customer_id];
            $limit = $t->credit_enabled && $t->credit_limit !== null ? Decimal::normalize((string) $t->credit_limit) : null;

            return ['customer_id' => (int) $t->customer_id, 'customer' => $t->display_name, 'credit_enabled' => (bool) $t->credit_enabled, 'credit_limit' => $limit,
                'payment_term_days' => $t->payment_term_days, 'policy' => $t->policy, 'financial_status' => $t->financial_status, 'currency_code' => $e['currency_code'],
                'exposure' => $e['exposure'], 'overdue' => $e['overdue'], 'available' => $limit !== null ? Decimal::subtract($limit, $e['exposure']) : null, 'mixed_currency' => $e['mixed_currency']];
        })->values();
        $exceptions = DB::table('customer_credit_exceptions as x')->join('customers as c', 'c.id', '=', 'x.customer_id')->where('x.organization_id', $organization->getKey())
            ->orderByDesc('x.id')->limit(100)->get(['x.id', 'x.customer_id', 'c.display_name', 'x.authorized_amount', 'x.reason', 'x.expires_on', 'x.consumed_at', 'x.sales_order_id', 'x.created_at']);

        return Inertia::render('Finance/Clients/Credit', [
            'organization' => $organization->only(['id', 'name']), 'enforcement' => CustomerCreditGuard::enforcement($organization), 'deductAdvances' => CustomerCreditGuard::deductsAdvances($organization), 'rows' => $rows, 'exceptions' => $exceptions,
            'customers' => $request->user()->hasPermission($organization, 'finance.customers.credit_override') ? Customer::query()->where('organization_id', $organization->getKey())->orderBy('display_name')->limit(1000)->get(['id', 'display_name']) : [],
            'can' => ['policy' => $request->user()->hasPermission($organization, 'finance.customers.terms.manage'), 'override' => $request->user()->hasPermission($organization, 'finance.customers.credit_override')],
            'truncated' => $terms->count() >= self::CAP,
        ]);
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
}
