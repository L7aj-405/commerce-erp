<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\Organization;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionReconciliationService;
use App\Services\Commissions\CommissionReportFilters;
use App\Services\Finance\Export\CommissionReportExcelExport;
use App\Services\Finance\Export\CommissionReportPdfExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Finance commission dashboard, salesperson drill-down and exports.
 *
 * Read-only: no ledger write, no state transition. Global visibility reuses
 * `commissions.ledger.view` (it already grants every salesperson's entries);
 * downloads require `commissions.export`; purchase cost / margin figures are
 * only serialized for users who also hold `product_cost.view`.
 */
class CommissionDashboardController extends Controller
{
    private const COST_FIELDS = ['cost', 'margin', 'margin_rate', 'commission_to_margin_ratio'];

    public function index(Request $request, ActiveTenantContext $context, CommissionDashboardService $dashboard, CommissionReconciliationService $reconciliation): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.ledger.view');
        $filters = CommissionReportFilters::fromRequest($request, $organization);
        $sorting = $request->validate([
            'sort' => ['nullable', Rule::in(CommissionDashboardService::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $sort = $sorting['sort'] ?? 'net';
        $direction = $sorting['direction'] ?? ($sort === 'salesperson_name' ? 'asc' : 'desc');
        $user = $request->user();
        $canCosts = $user->hasPermission($organization, 'product_cost.view');
        $canReconcile = $user->hasPermission($organization, 'commissions.ledger.reconcile');
        $stores = $this->stores($organization);

        return Inertia::render('Finance/CommissionDashboard', [
            'organization' => $organization->only(['id', 'name']),
            'filters' => $filters->toArray(),
            'sorting' => ['sort' => $sort, 'direction' => $direction],
            'stores' => $stores,
            'salespeople' => $this->salespeople($organization),
            'kpis' => $this->strip($dashboard->kpis($organization, $filters), $canCosts),
            'ranking' => array_map(fn (array $row) => $this->strip($row, $canCosts), $dashboard->bySalesperson($organization, $filters, $sort, $direction)),
            'trend' => $dashboard->trend($organization, $filters),
            'statuses' => $dashboard->statusBreakdown($organization, $filters),
            'tiers' => array_map(fn (array $row) => $canCosts ? $row : array_diff_key($row, ['margin' => true]), $dashboard->tierBreakdown($organization, $filters)),
            'storeBreakdown' => count($stores) > 1
                ? array_map(fn (array $row) => $this->strip($row, $canCosts), $dashboard->storeBreakdown($organization, $filters))
                : null,
            'adjustments' => $dashboard->adjustments($organization, $filters),
            'health' => $dashboard->health($organization, $filters),
            // The full C5 rule/missing-entry scan is bounded but not cheap: it
            // only runs when the page explicitly asks for it (partial reload).
            'reconciliation' => Inertia::optional(fn () => $canReconcile
                ? $reconciliation->scan($organization, $filters->from, $filters->to, $filters->store, $filters->unattributed ? null : $filters->salespersonId, 50)['counts']
                : null),
            'can' => [
                'costs' => $canCosts,
                'export' => $user->hasPermission($organization, 'commissions.export'),
                'reconcile' => $canReconcile,
                'rules' => $user->hasPermission($organization, 'commissions.rules.view'),
            ],
        ]);
    }

    public function salesperson(Request $request, ActiveTenantContext $context, CommissionDashboardService $dashboard, string $salesperson): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.ledger.view');
        $unattributed = $salesperson === 'unattributed';
        $salespersonId = $unattributed ? null : CommissionReportFilters::resolveSalesperson($organization, (int) $salesperson);
        $filters = CommissionReportFilters::fromRequest($request, $organization, allowSalesperson: false)->withSalesperson($salespersonId, $unattributed);
        $canCosts = $request->user()->hasPermission($organization, 'product_cost.view');

        $columns = [
            'id', 'sales_order_id', 'customer_return_id', 'salesperson_id', 'salesperson_name_snapshot', 'store_id', 'occurred_at', 'sale_date',
            'entry_type', 'status', 'product_name_snapshot', 'line_reference_snapshot', 'quantity_snapshot', 'revenue_ht_snapshot',
            'commission_rate_snapshot', 'commission_amount', 'commission_rule_set_name_snapshot', 'reversal_reason', 'approved_at', 'paid_at',
        ];
        if ($canCosts) {
            array_push($columns, 'margin_amount_snapshot', 'margin_rate_snapshot');
        }

        $entries = CommissionEntry::query()
            ->where('organization_id', $organization->getKey())
            ->whereBetween('occurred_at', [$filters->fromTimestamp(), $filters->toTimestamp()])
            ->when($unattributed, fn ($query) => $query->whereNull('salesperson_id'), fn ($query) => $query->where('salesperson_id', $salespersonId))
            ->when($filters->store, fn ($query, $store) => $query->where('store_id', $store->getKey()))
            ->when($filters->status, fn ($query, $status) => $query->where('status', $status))
            ->when($filters->entryType, fn ($query, $type) => $query->where('entry_type', $type))
            ->with(['salesOrder:id,order_number', 'customerReturn:id,return_number'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->select($columns)
            ->paginate(25)->withQueryString();

        $name = $unattributed ? 'Non attribuée' : (CommissionEntry::query()->where('organization_id', $organization->getKey())
            ->where('salesperson_id', $salespersonId)->latest('id')->value('salesperson_name_snapshot')
            ?? DB::table('users')->where('id', $salespersonId)->value('name'));

        return Inertia::render('Finance/CommissionSalesperson', [
            'organization' => $organization->only(['id', 'name']),
            'salesperson' => ['key' => $unattributed ? 'unattributed' : (string) $salespersonId, 'name' => $name, 'is_unattributed' => $unattributed],
            'filters' => $filters->toArray(),
            'stores' => $this->stores($organization),
            'kpis' => $this->strip($dashboard->kpis($organization, $filters), $canCosts),
            'entries' => $entries,
            'can' => ['costs' => $canCosts],
        ]);
    }

    public function exportXlsx(Request $request, ActiveTenantContext $context, CommissionReportExcelExport $export, AuditLogger $audit): BinaryFileResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.export');
        $filters = CommissionReportFilters::fromRequest($request, $organization);
        $withCosts = $request->user()->hasPermission($organization, 'product_cost.view');

        $result = $export->build($organization, $filters, $withCosts, $this->filterLabel($organization, $filters));
        $this->auditExport($audit, $request, $organization, $filters, 'xlsx', $result['rows'], $withCosts);

        return response()->download($result['path'], $result['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    public function exportPdf(Request $request, ActiveTenantContext $context, CommissionReportPdfExport $export, AuditLogger $audit): HttpResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorizePermission($request, $organization, 'commissions.export');
        $filters = CommissionReportFilters::fromRequest($request, $organization);
        $withCosts = $request->user()->hasPermission($organization, 'product_cost.view');

        $result = $export->build($organization, $filters, $withCosts, $this->filterLabel($organization, $filters));
        $this->auditExport($audit, $request, $organization, $filters, 'pdf', $result['rows'], $withCosts);

        return response($result['bytes'], 200, [
            'Content-Type' => $result['mime'],
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Content-Length' => (string) strlen($result['bytes']),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /** Audit scope only — filters, format and row count; never the exported data. */
    private function auditExport(AuditLogger $audit, Request $request, Organization $organization, CommissionReportFilters $filters, string $format, int $rows, bool $withCosts): void
    {
        $audit->record('commission.report_exported', $request->user(), $organization, $filters->store, newValues: [
            'format' => $format,
            'filters' => $filters->toArray(),
            'row_count' => $rows,
            'includes_costs' => $withCosts,
        ]);
    }

    private function filterLabel(Organization $organization, CommissionReportFilters $filters): string
    {
        $parts = [$filters->store ? 'Magasin : '.$filters->store->name : 'Tous les magasins'];
        if ($filters->unattributed) {
            $parts[] = 'Commercial : Non attribuée';
        } elseif ($filters->salespersonId !== null) {
            $parts[] = 'Commercial : '.(CommissionEntry::query()->where('organization_id', $organization->getKey())
                ->where('salesperson_id', $filters->salespersonId)->latest('id')->value('salesperson_name_snapshot') ?? '#'.$filters->salespersonId);
        }
        if ($filters->status) {
            $parts[] = 'Statut : '.CommissionReportExcelExport::statusLabel($filters->status);
        }
        if ($filters->entryType) {
            $parts[] = 'Type : '.CommissionReportExcelExport::typeLabel($filters->entryType);
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $row */
    private function strip(array $row, bool $canCosts): array
    {
        return $canCosts ? $row : array_diff_key($row, array_flip(self::COST_FIELDS));
    }

    /** All stores (archived ones included) so historical store entries stay filterable. */
    private function stores(Organization $organization): array
    {
        return Store::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name', 'code'])->toArray();
    }

    /** Salespeople as attributed historically on this organization's ledger. */
    private function salespeople(Organization $organization): array
    {
        return DB::table('commission_entries')
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('salesperson_id')
            ->groupBy('salesperson_id')
            ->selectRaw('salesperson_id as id, MAX(salesperson_name_snapshot) as name')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
            ->all();
    }

    private function authorizePermission(Request $request, Organization $organization, string $permission): void
    {
        abort_unless($request->user()->hasPermission($organization, $permission), 403);
    }
}
