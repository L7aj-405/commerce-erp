<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
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
