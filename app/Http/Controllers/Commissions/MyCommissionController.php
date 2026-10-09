<?php

namespace App\Http\Controllers\Commissions;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Services\ActiveTenantContext;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionReportFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salesperson self-service view (`commissions.own.view`).
 *
 * The salesperson scope is always the authenticated user: no salesperson or
 * store parameter is read from the request, so it cannot be widened by URL
 * manipulation. Purchase cost is never serialized; the margin snapshot is
 * only exposed to users who separately hold `product_cost.view`.
 */
class MyCommissionController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, CommissionDashboardService $dashboard): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        abort_unless($user->hasPermission($organization, 'commissions.own.view'), 403);

        $data = $request->validate([
            'period' => ['nullable', Rule::in(CommissionReportFilters::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(CommissionReportFilters::STATUSES)],
            'entry_type' => ['nullable', Rule::in(CommissionReportFilters::ENTRY_TYPES)],
        ]);
        [$period, $from, $to] = CommissionReportFilters::resolvePeriod($data['period'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
        $filters = new CommissionReportFilters($period, $from, $to, null, (int) $user->getKey(), false, $data['status'] ?? null, $data['entry_type'] ?? null);
        $canMargin = $user->hasPermission($organization, 'product_cost.view');

        $columns = [
            'id', 'sales_order_id', 'customer_return_id', 'occurred_at', 'sale_date', 'entry_type', 'status',
            'product_name_snapshot', 'commission_rate_snapshot', 'commission_amount', 'reversal_reason', 'approved_at', 'paid_at',
        ];
        if ($canMargin) {
            array_push($columns, 'margin_amount_snapshot', 'margin_rate_snapshot');
        }

        $entries = CommissionEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('salesperson_id', $user->getKey())
            ->whereBetween('occurred_at', [$filters->fromTimestamp(), $filters->toTimestamp()])
            ->when($filters->status, fn ($query, $status) => $query->where('status', $status))
            ->when($filters->entryType, fn ($query, $type) => $query->where('entry_type', $type))
            ->with(['salesOrder:id,order_number', 'customerReturn:id,return_number'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->select($columns)
            ->paginate(25)->withQueryString();

        $kpis = $dashboard->kpis($organization, $filters);

        return Inertia::render('Commissions/MyCommissions', [
            'filters' => $filters->toArray(),
            'summary' => array_intersect_key($kpis, array_flip([
                'gross', 'reversals', 'return_reversals', 'cancellations', 'corrections', 'net', 'pending', 'approved', 'paid', 'entry_count', 'order_count',
            ])),
            'entries' => $entries,
            'can' => ['margin' => $canMargin],
        ]);
    }
}
