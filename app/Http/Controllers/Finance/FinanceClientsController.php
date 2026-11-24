<?php

namespace App\Http\Controllers\Finance;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\CustomerFinancialSummary;
use App\Services\DocumentVisibility;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance clients — entry pages (Phase 1: UI/UX organization only).
 *
 * Every page is READ-ONLY and composes existing authorized services. Nothing here
 * defines or changes a financial rule, a permission, a data scope or a calculation:
 *  - Finance authorization is the existing FinanceAccessGuard (organization + finance.view,
 *    store filter independent of the active-store switcher), checked server-side on every
 *    page including the placeholders.
 *  - "Comptes clients" additionally requires the existing customers.view / Customer policy and
 *    shows document figures only through CustomerFinancialSummary + DocumentVisibility
 *    (viewer-scoped), exactly like the commercial customer profile.
 *  - The pages for facturation, encaissements, créances and journal are authorized route
 *    aliases of the existing controllers (see routes/web.php), not copies.
 *  - Test Mode: none of these routes is in the sandbox allowlist, so Test Mode requests are
 *    redirected exactly like every other Finance report.
 */
class FinanceClientsController extends Controller
{
    /** Pages whose functionality is announced but not built yet (no data, no actions). */
    private const PLANNED = ['credit', 'instruments', 'personnel'];

    /** FC-01 — customer-finance dashboard: authoritative metrics already produced by the monthly situation. */
    public function overview(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceMonthlyReportService $report): Response
    {
        [$organization, $period, $store] = $this->scope($request, $context, $guard);
        $situation = $report->situation($organization, $period, $store);

        return Inertia::render('Finance/Clients/Overview', $this->base($organization, $period, $store) + [
            'metrics' => [
                'sales' => $situation['ventes_nettes'],
                'billing' => $situation['facturation'],
                'collections' => $situation['encaissements'],
                'receivables' => $situation['creances_fin'],
                'refunds' => $situation['remboursements'],
                'refund_obligations' => $situation['obligations_remboursement'],
                // No reliable organization-wide aggregate exists (the unbilled position is computed per customer
                // on the account page): shown as unavailable, never as zero.
                'unbilled_orders' => null,
            ],
            'can' => ['accounts' => $request->user()->hasPermission($organization, 'customers.view')],
        ]);
    }

    /** FC-02 — customer accounts index: identity only. No per-row financial figure (those are viewer-scoped, computed on the account). */
    public function accounts(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $this->authorize('viewAny', [Customer::class, $organization]);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::enum(CustomerStatus::class)]]);
        $customers = Customer::query()->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('display_name', 'like', "%{$search}%")->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('display_name')
            ->paginate(20, ['id', 'display_name', 'company_name', 'email', 'phone', 'status'])
            ->withQueryString();

        return Inertia::render('Finance/Clients/Accounts', ['customers' => $customers, 'filters' => $filters]);
    }

    /** FC-02 — one customer account: viewer-scoped figures and recent documents, same visibility rules as the commercial profile. */
    public function accountShow(Request $request, Customer $customer, FinanceAccessGuard $guard, CustomerFinancialSummary $summary, DocumentVisibility $visibility): Response
    {
        $organization = $customer->organization;
        $guard->authorizeView($request->user(), $organization);
        $this->authorize('view', $customer);
        $user = $request->user();
        $customerId = $customer->getKey();
        $stores = $visibility->storeIds($user, $organization) ?: [0];
        $limit = 15;

        $invoices = $visibility->visible($user, $organization, Invoice::class)
            ->where('customer_id', $customerId)->where('status', '!=', InvoiceStatus::Superseded->value);
        $payments = $visibility->visible($user, $organization, Payment::class)
            ->whereHas('allocations.salesOrder', fn ($query) => $query->where('customer_id', $customerId)->whereIn('store_id', $stores));

        return Inertia::render('Finance/Clients/AccountShow', [
            'customer' => $customer->only(['id', 'display_name', 'company_name', 'email', 'phone', 'status']),
            'summary' => $summary->forCustomer($customer, $user),
            'invoices' => (clone $invoices)->orderByDesc('invoice_date')->limit($limit)->get(['id', 'invoice_number', 'version', 'invoice_date', 'status', 'total_incl_tax']),
            'invoicesCount' => (clone $invoices)->count(),
            'payments' => (clone $payments)->orderByDesc('payment_date')->limit($limit)->get(['id', 'payment_number', 'payment_date', 'method', 'status', 'amount']),
            'paymentsCount' => (clone $payments)->count(),
            'can' => ['commercialProfile' => $user->can('view', $customer)],
        ]);
    }

    /** FC-06 / FC-07 and the personnel placeholder: complete page shells, server-authorized, no data. */
    public function planned(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, string $page): Response
    {
        abort_unless(in_array($page, self::PLANNED, true), 404);
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);

        return Inertia::render('Finance/Clients/Planned', ['page' => $page, 'organization' => $organization->only(['id', 'name'])]);
    }

    /** FC-09 — reports hub: links to the existing reports and exports (their own routes keep their own permissions). */
    public function reports(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard): Response
    {
        [$organization, $period, $store] = $this->scope($request, $context, $guard);

        return Inertia::render('Finance/Clients/Reports', $this->base($organization, $period, $store) + [
            'can' => ['export' => $request->user()->hasPermission($organization, 'finance.export')],
        ]);
    }

    /** @return array{0: Organization, 1: FinancePeriod, 2: ?Store} */
    private function scope(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard): array
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $month = $request->query('month');
        $period = $month ? FinancePeriod::fromMonth($month) : FinancePeriod::current($organization);

        return [$organization, $period, $guard->resolveStore($organization, $request->query('store_id'))];
    }

    /** @return array<string, mixed> */
    private function base(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        return [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'stores' => Store::query()->where('organization_id', $organization->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code'])->toArray(),
        ];
    }
}
