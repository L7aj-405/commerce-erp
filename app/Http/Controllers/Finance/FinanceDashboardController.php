<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\Dashboards\ExecutiveDashboardService;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReceivablesService;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Situation mensuelle + its four drill-downs.
 *
 * Authorization is Finance's own path (FinanceAccessGuard): organization +
 * finance.view only. The organization itself still comes from
 * ActiveTenantContext (that's just "which tenant is this user working in" —
 * completely normal), but the STORE filter is independent of the user's
 * active-store switcher: an explicit, validated `store_id` query parameter,
 * defaulting to "all stores in the organization." See FinanceAccessGuard for
 * why InvoicePolicy/PaymentPolicy are not reused here.
 */
class FinanceDashboardController extends Controller
{
    public function index(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        ExecutiveDashboardService $dashboard,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);

        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));
        $analytics = $dashboard->finance($organization, $period, $store);

        return Inertia::render('Finance/Situation', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'storeId' => $store?->id,
            'stores' => $this->stores($organization),
            'situation' => $analytics['situation'],
            'dashboard' => $analytics,
            'can' => [
                'receivables' => $request->user()->hasPermission($organization, 'finance.receivables.view')
                    || $request->user()->hasPermission($organization, 'finance.view'),
                'export' => $request->user()->hasPermission($organization, 'finance.export'),
            ],
        ]);
    }

    public function ventes(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        FinanceMonthlyReportService $report,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        return Inertia::render('Finance/Ventes', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'stores' => $this->stores($organization),
            'orders' => $report->ventesBreakdown($organization, $period, $store),
        ]);
    }

    public function facturation(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        FinanceInvoiceReadModel $invoices,
        FinanceMonthlyReportService $report,
        FinanceReceivablesService $receivables,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));
        $section = in_array($request->query('section'), ['credit_notes', 'unbilled', 'summary'], true) ? $request->query('section') : 'invoices';

        return Inertia::render('Finance/Facturation', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'stores' => $this->stores($organization),
            // Section data is computed only for the requested tab (invoices stays the default and the original payload).
            'invoices' => $section === 'invoices' ? $invoices->issuedDuring($organization, $period, $store) : ['data' => [], 'links' => [], 'total' => 0],
            'creditNotes' => $section === 'credit_notes' ? $invoices->creditNotesDuring($organization, $period, $store) : null,
            'unbilledOrders' => $section === 'unbilled' ? $receivables->unbilledOrdersAsOf($organization, $period->end, $store) : null,
            // Additive, read-only (Finance clients "Synthèse"): the same two totals the monthly situation already uses.
            'summary' => $this->invoicingSummary($report, $organization, $period, $store),
        ]);
    }

    public function encaissements(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        FinanceMonthlyReportService $report,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        return Inertia::render('Finance/Encaissements', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'stores' => $this->stores($organization),
            'payments' => $report->encaissementsBreakdown($organization, $period, $store),
            'refunds' => $report->refundsBreakdown($organization, $period, $store),
        ]);
    }

    public function creances(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        FinanceReceivablesService $receivables,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeReceivables($request->user(), $organization);
        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));
        $receivableData = $receivables->salesOrderReceivablesAsOf($organization, $period->end, $store);

        return Inertia::render('Finance/Creances', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'asOf' => $period->end->toDateString(),
            'storeId' => $store?->id,
            'stores' => $this->stores($organization),
            'total' => $receivableData['total'],
            'orders' => $receivableData['orders'],
        ]);
    }

    /**
     * "CA encaissé" — the accountant-facing breakdown of the Encaissements
     * headline number: one row per Posted payment allocation whose
     * payment_date falls in the selected month (never sale_date /
     * invoice_date — see FinanceCaEncaisseService).
     */
    public function caEncaisse(
        Request $request,
        ActiveTenantContext $context,
        FinanceAccessGuard $guard,
        FinanceCaEncaisseService $caEncaisse,
    ): Response {
        $organization = $context->organizationOrFail();
        $guard->authorizeView($request->user(), $organization);
        $period = $this->period($request);
        $store = $guard->resolveStore($organization, $request->query('store_id'));

        return Inertia::render('Finance/CaEncaisse', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'stores' => $this->stores($organization),
            'total' => $caEncaisse->total($organization, $period, $store),
            'rows' => $caEncaisse->rows($organization, $period, $store),
            'can' => ['export' => $request->user()->hasPermission($organization, 'finance.export')],
        ]);
    }

    /** @return array{gross: string, credit_notes: string, net: string} */
    private function invoicingSummary(FinanceMonthlyReportService $report, Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $gross = $report->facturationTotal($organization, $period, $store);
        $credits = $report->creditNotesTotal($organization, $period, $store);

        return ['gross' => $gross, 'credit_notes' => $credits, 'net' => Decimal::subtract($gross, $credits)];
    }

    private function period(Request $request): FinancePeriod
    {
        $month = $request->query('month');

        return $month ? FinancePeriod::fromMonth($month) : FinancePeriod::current(app(ActiveTenantContext::class)->organizationOrFail());
    }

    /** @return list<array<string, mixed>> */
    private function stores(Organization $organization): array
    {
        return Store::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->toArray();
    }
}
