<?php

namespace App\Services\Commissions;

use App\Enums\SalesOrderStatus;
use App\Models\CommissionEntry;
use App\Models\CommissionRuleSet;
use App\Models\Organization;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Read-only Finance commission analytics.
 *
 * Every commission number comes from commission_entries (SQL aggregates over
 * the immutable ledger, signed amounts as stored). Margin context uses the
 * revenue/cost/margin snapshots copied onto each entry at calculation time,
 * so the commission-to-margin ratio always compares the same lines. Nothing
 * here reads current rules, current purchase prices or current salesperson
 * assignments, and nothing here writes.
 */
final class CommissionDashboardService
{
    public const SORTABLE = ['salesperson_name', 'orders', 'revenue', 'margin', 'gross', 'reversals', 'net', 'pending', 'approved', 'paid'];

    /** Base ledger query with every global filter applied. */
    public function base(Organization $organization, CommissionReportFilters $filters): Builder
    {
        return DB::table('commission_entries as ce')
            ->where('ce.organization_id', $organization->getKey())
            ->whereBetween('ce.occurred_at', [$filters->fromTimestamp(), $filters->toTimestamp()])
            ->when($filters->store, fn ($query, $store) => $query->where('ce.store_id', $store->getKey()))
            ->when($filters->unattributed, fn ($query) => $query->whereNull('ce.salesperson_id'))
            ->when(! $filters->unattributed && $filters->salespersonId !== null, fn ($query) => $query->where('ce.salesperson_id', $filters->salespersonId))
            ->when($filters->status, fn ($query, $status) => $query->where('ce.status', $status))
            ->when($filters->entryType, fn ($query, $type) => $query->where('ce.entry_type', $type));
    }

    /** @return array<string, mixed> */
    public function kpis(Organization $organization, CommissionReportFilters $filters): array
    {
        $row = $this->withAmountColumns($this->base($organization, $filters))
            ->selectRaw('COUNT(*) as entry_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN ce.entry_type = 'sale' THEN ce.sales_order_id END) as order_count")
            ->selectRaw('COUNT(DISTINCT ce.salesperson_id) as salesperson_count')
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'return_reversal' THEN ce.commission_amount ELSE 0 END) as return_reversals")
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'cancellation' THEN ce.commission_amount ELSE 0 END) as cancellations")
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'correction' THEN ce.commission_amount ELSE 0 END) as corrections")
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'manual_adjustment' THEN ce.commission_amount ELSE 0 END) as manual_adjustments")
            ->first();

        return [
            ...$this->amountColumns($row),
            'return_reversals' => $this->money($row->return_reversals),
            'cancellations' => $this->money($row->cancellations),
            'corrections' => $this->money($row->corrections),
            'manual_adjustments' => $this->money($row->manual_adjustments),
            'entry_count' => (int) $row->entry_count,
            'order_count' => (int) $row->order_count,
            'salesperson_count' => (int) $row->salesperson_count,
        ];
    }

    /**
     * One grouped query; the null salesperson bucket is returned explicitly as
     * "Non attribuée" and flagged non-payable.
     *
     * @return list<array<string, mixed>>
     */
    public function bySalesperson(Organization $organization, CommissionReportFilters $filters, string $sort = 'net', string $direction = 'desc'): array
    {
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'net';
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $rows = $this->withAmountColumns($this->base($organization, $filters))
            ->addSelect('ce.salesperson_id')
            ->selectRaw('MAX(ce.salesperson_name_snapshot) as salesperson_name')
            ->selectRaw("COUNT(DISTINCT CASE WHEN ce.entry_type = 'sale' THEN ce.sales_order_id END) as orders")
            ->selectRaw('COUNT(*) as entry_count')
            ->groupBy('ce.salesperson_id')
            ->get();

        return $rows->map(fn ($row) => [
            'salesperson_id' => $row->salesperson_id === null ? null : (int) $row->salesperson_id,
            'salesperson_name' => $row->salesperson_id === null ? 'Non attribuée' : ($row->salesperson_name ?: 'Commercial #'.$row->salesperson_id),
            'is_unattributed' => $row->salesperson_id === null,
            'orders' => (int) $row->orders,
            'entry_count' => (int) $row->entry_count,
            ...$this->amountColumns($row),
        ])->sort(function (array $a, array $b) use ($sort, $direction) {
            $result = match ($sort) {
                'salesperson_name' => strcasecmp($a['salesperson_name'], $b['salesperson_name']),
                'orders' => $a['orders'] <=> $b['orders'],
                default => Decimal::compare($a[$sort], $b[$sort]),
            };

            return $direction === 'asc' ? $result : -$result;
        })->values()->all();
    }

    /**
     * Aggregated by day in SQL (DATE() is portable across MySQL and SQLite),
     * then folded into week/month buckets for longer ranges.
     *
     * @return array{granularity:string,points:list<array<string,mixed>>}
     */
    public function trend(Organization $organization, CommissionReportFilters $filters): array
    {
        $days = $filters->dayCount();
        $granularity = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');

        $rows = $this->base($organization, $filters)
            ->selectRaw('DATE(ce.occurred_at) as day')
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'sale' THEN ce.commission_amount ELSE 0 END) as gross")
            ->selectRaw("SUM(CASE WHEN ce.entry_type <> 'sale' THEN ce.commission_amount ELSE 0 END) as reversals")
            ->selectRaw('SUM(ce.commission_amount) as net')
            ->groupByRaw('DATE(ce.occurred_at)')
            ->orderBy('day')
            ->get();

        $buckets = [];
        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->day);
            [$key, $label] = match ($granularity) {
                'day' => [$date->toDateString(), $date->format('d/m')],
                'week' => [$date->startOfWeek()->toDateString(), 'Sem. '.$date->startOfWeek()->format('d/m')],
                default => [$date->format('Y-m'), $date->format('m/Y')],
            };
            $buckets[$key] ??= ['key' => $key, 'label' => $label, 'gross' => '0.0000', 'reversals' => '0.0000', 'net' => '0.0000'];
            foreach (['gross', 'reversals', 'net'] as $column) {
                $buckets[$key][$column] = Decimal::add($buckets[$key][$column], $this->money($row->{$column}));
            }
        }

        return ['granularity' => $granularity, 'points' => array_values($buckets)];
    }

    /** @return list<array{status:string,amount:string,count:int}> */
    public function statusBreakdown(Organization $organization, CommissionReportFilters $filters): array
    {
        $rows = $this->base($organization, $filters)
            ->select('ce.status')
            ->selectRaw('SUM(ce.commission_amount) as amount')
            ->selectRaw('COUNT(*) as entry_count')
            ->groupBy('ce.status')
            ->get()
            ->keyBy('status');

        return array_map(fn (string $status) => [
            'status' => $status,
            'amount' => $this->money($rows->get($status)?->amount),
            'count' => (int) ($rows->get($status)?->entry_count ?? 0),
        ], CommissionReportFilters::STATUSES);
    }

    /**
     * Grouped by the tier snapshot stored on each entry (rule-set name, margin
     * bounds, rate). Live commission_rule_tiers are never joined, so archiving
     * or replacing a rule version cannot rewrite what was historically earned.
     *
     * @return list<array<string, mixed>>
     */
    public function tierBreakdown(Organization $organization, CommissionReportFilters $filters): array
    {
        return $this->base($organization, $filters)
            ->select(['ce.commission_rule_set_name_snapshot', 'ce.rule_min_margin_snapshot', 'ce.rule_max_margin_snapshot', 'ce.commission_rate_snapshot'])
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'sale' THEN 1 ELSE 0 END) as sale_lines")
            ->selectRaw('COUNT(*) as entry_count')
            ->selectRaw('SUM(ce.revenue_ht_snapshot) as revenue')
            ->selectRaw('SUM(ce.margin_amount_snapshot) as margin')
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'sale' THEN ce.commission_amount ELSE 0 END) as gross")
            ->selectRaw('SUM(ce.commission_amount) as net')
            ->groupBy(['ce.commission_rule_set_name_snapshot', 'ce.rule_min_margin_snapshot', 'ce.rule_max_margin_snapshot', 'ce.commission_rate_snapshot'])
            ->orderBy('ce.commission_rule_set_name_snapshot')
            ->orderBy('ce.rule_min_margin_snapshot')
            ->orderBy('ce.commission_rate_snapshot')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'rule_set' => $row->commission_rule_set_name_snapshot,
                'min_margin' => $row->rule_min_margin_snapshot === null ? null : $this->money($row->rule_min_margin_snapshot),
                'max_margin' => $row->rule_max_margin_snapshot === null ? null : $this->money($row->rule_max_margin_snapshot),
                'commission_rate' => $this->money($row->commission_rate_snapshot),
                'sale_lines' => (int) $row->sale_lines,
                'entry_count' => (int) $row->entry_count,
                'revenue' => $this->money($row->revenue),
                'margin' => $this->money($row->margin),
                'gross' => $this->money($row->gross),
                'net' => $this->money($row->net),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function storeBreakdown(Organization $organization, CommissionReportFilters $filters): array
    {
        return $this->withAmountColumns($this->base($organization, $filters))
            ->leftJoin('stores', function ($join) {
                $join->on('stores.id', '=', 'ce.store_id')->on('stores.organization_id', '=', 'ce.organization_id');
            })
            ->addSelect(['ce.store_id', 'stores.name as store_name'])
            ->groupBy('ce.store_id', 'stores.name')
            ->orderBy('stores.name')
            ->get()
            ->map(fn ($row) => [
                'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                'store_name' => $row->store_name ?? 'Sans magasin',
                ...$this->amountColumns($row),
            ])->values()->all();
    }

    /**
     * Returns / cancellations / corrections made visible instead of being
     * folded silently into net: totals per type and per salesperson, plus the
     * latest adjustment rows (bounded) with their reason and source document.
     *
     * @return array{by_type:list<array<string,mixed>>,by_salesperson:list<array<string,mixed>>,recent:list<array<string,mixed>>}
     */
    public function adjustments(Organization $organization, CommissionReportFilters $filters, int $limit = 15): array
    {
        $adjustments = fn () => $this->base($organization, $filters)->where('ce.entry_type', '<>', CommissionEntry::TYPE_SALE);

        $byType = $adjustments()
            ->select('ce.entry_type')
            ->selectRaw('SUM(ce.commission_amount) as amount')
            ->selectRaw('COUNT(*) as entry_count')
            ->groupBy('ce.entry_type')
            ->get()
            ->map(fn ($row) => ['entry_type' => $row->entry_type, 'amount' => $this->money($row->amount), 'count' => (int) $row->entry_count])
            ->values()->all();

        $bySalesperson = $adjustments()
            ->select('ce.salesperson_id')
            ->selectRaw('MAX(ce.salesperson_name_snapshot) as salesperson_name')
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'return_reversal' THEN ce.commission_amount ELSE 0 END) as return_reversals")
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'cancellation' THEN ce.commission_amount ELSE 0 END) as cancellations")
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'correction' THEN ce.commission_amount ELSE 0 END) as corrections")
            ->selectRaw('SUM(ce.commission_amount) as total')
            ->groupBy('ce.salesperson_id')
            ->orderByRaw('SUM(ce.commission_amount) asc')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'salesperson_id' => $row->salesperson_id === null ? null : (int) $row->salesperson_id,
                'salesperson_name' => $row->salesperson_id === null ? 'Non attribuée' : $row->salesperson_name,
                'return_reversals' => $this->money($row->return_reversals),
                'cancellations' => $this->money($row->cancellations),
                'corrections' => $this->money($row->corrections),
                'total' => $this->money($row->total),
            ])->values()->all();

        $recent = $adjustments()
            ->leftJoin('sales_orders as so', 'so.id', '=', 'ce.sales_order_id')
            ->leftJoin('customer_returns as cr', 'cr.id', '=', 'ce.customer_return_id')
            ->select([
                'ce.id', 'ce.occurred_at', 'ce.sale_date', 'ce.entry_type', 'ce.status', 'ce.commission_amount', 'ce.salesperson_id',
                'ce.salesperson_name_snapshot', 'ce.product_name_snapshot', 'ce.reversal_reason', 'ce.sales_order_id',
                'so.order_number', 'ce.customer_return_id', 'cr.return_number',
            ])
            ->orderByDesc('ce.occurred_at')->orderByDesc('ce.id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'occurred_at' => (string) $row->occurred_at,
                'sale_date' => (string) $row->sale_date,
                'entry_type' => $row->entry_type,
                'status' => $row->status,
                'commission_amount' => $this->money($row->commission_amount),
                'salesperson_name' => $row->salesperson_id === null ? 'Non attribuée' : $row->salesperson_name_snapshot,
                'product' => $row->product_name_snapshot,
                'reason' => $row->reversal_reason,
                'sales_order_id' => (int) $row->sales_order_id,
                'order_number' => $row->order_number,
                'return_number' => $row->return_number,
            ])->values()->all();

        return ['by_type' => $byType, 'by_salesperson' => $bySalesperson, 'recent' => $recent];
    }

    /**
     * Cheap, index-backed health counts mirroring CommissionEligibilityService's
     * precedence (salesperson → cost → margin). The expensive per-line rule /
     * missing-entry scan stays the explicit C5 CommissionReconciliationService,
     * loaded on demand only.
     *
     * @return array<string, int|bool>
     */
    public function health(Organization $organization, CommissionReportFilters $filters): array
    {
        $row = DB::table('sales_order_lines as lines')
            ->join('sales_orders as orders', function ($join) {
                $join->on('orders.id', '=', 'lines.sales_order_id')->on('orders.organization_id', '=', 'lines.organization_id');
            })
            ->where('orders.organization_id', $organization->getKey())
            ->where('orders.status', SalesOrderStatus::Confirmed->value)
            ->whereBetween('orders.sale_date', [$filters->from, $filters->to])
            ->when($filters->store, fn ($query, $store) => $query->where('orders.store_id', $store->getKey()))
            ->when($filters->unattributed, fn ($query) => $query->whereNull('orders.salesperson_id'))
            ->when(! $filters->unattributed && $filters->salespersonId !== null, fn ($query) => $query->where('orders.salesperson_id', $filters->salespersonId))
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw("SUM(CASE WHEN orders.salesperson_id IS NULL OR orders.salesperson_name_snapshot IS NULL OR orders.salesperson_name_snapshot = '' THEN 1 ELSE 0 END) as missing_salesperson")
            ->selectRaw("SUM(CASE WHEN orders.salesperson_id IS NOT NULL AND orders.salesperson_name_snapshot IS NOT NULL AND orders.salesperson_name_snapshot <> '' AND (lines.cost_status IS NULL OR lines.cost_status = 'missing') THEN 1 ELSE 0 END) as missing_cost")
            ->selectRaw("SUM(CASE WHEN orders.salesperson_id IS NOT NULL AND orders.salesperson_name_snapshot IS NOT NULL AND orders.salesperson_name_snapshot <> '' AND lines.cost_status IS NOT NULL AND lines.cost_status <> 'missing' AND (lines.cost_status <> 'available' OR lines.margin_amount_snapshot IS NULL OR lines.margin_rate_snapshot IS NULL) THEN 1 ELSE 0 END) as margin_unavailable")
            ->first();

        return [
            'confirmed_line_count' => (int) $row->line_count,
            'missing_salesperson' => (int) $row->missing_salesperson,
            'missing_cost' => (int) $row->missing_cost,
            'margin_unavailable' => (int) $row->margin_unavailable,
            'has_active_rules' => CommissionRuleSet::query()->where('organization_id', $organization->getKey())->where('status', CommissionRuleSet::STATUS_ACTIVE)->exists(),
            'has_any_entries' => DB::table('commission_entries')->where('organization_id', $organization->getKey())->exists(),
        ];
    }

    /**
     * Constant-memory export stream (keyset chunks by id), joined to the order
     * number and store name only.
     *
     * @return LazyCollection<int, object>
     */
    public function exportRows(Organization $organization, CommissionReportFilters $filters): LazyCollection
    {
        return $this->base($organization, $filters)
            ->leftJoin('sales_orders as so', 'so.id', '=', 'ce.sales_order_id')
            ->leftJoin('stores as st', 'st.id', '=', 'ce.store_id')
            ->select([
                'ce.id', 'ce.occurred_at', 'ce.sale_date', 'ce.salesperson_id', 'ce.salesperson_name_snapshot', 'st.name as store_name',
                'so.order_number', 'ce.product_name_snapshot', 'ce.line_reference_snapshot', 'ce.entry_type', 'ce.status',
                'ce.revenue_ht_snapshot', 'ce.cost_total_snapshot', 'ce.margin_amount_snapshot', 'ce.margin_rate_snapshot',
                'ce.commission_rate_snapshot', 'ce.commission_amount', 'ce.commission_rule_set_name_snapshot',
                'ce.rule_min_margin_snapshot', 'ce.rule_max_margin_snapshot', 'ce.paid_at',
            ])
            ->lazyById(1000, 'ce.id', 'id');
    }

    /** Ratio helpers — null whenever the denominator is zero or not positive. */
    public function marginRate(string $margin, string $revenue): ?string
    {
        return Decimal::compare($revenue, '0') > 0 ? Decimal::divide(Decimal::multiply($margin, '100'), $revenue) : null;
    }

    /**
     * Net commission / gross margin × 100. Undefined (null) when margin is zero
     * or negative: a percentage of a loss is not a meaningful payout ratio.
     */
    public function commissionToMarginRatio(string $netCommission, string $margin): ?string
    {
        return Decimal::compare($margin, '0') > 0 ? Decimal::divide(Decimal::multiply($netCommission, '100'), $margin) : null;
    }

    public function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }
        if (is_int($value) || is_float($value)) {
            return Decimal::normalize($value);
        }
        $value = trim((string) $value);
        if (preg_match('/^[+-]?\d+(\.\d{0,4})?$/', $value)) {
            return Decimal::normalize(rtrim($value, '.'));
        }

        // SQLite may return floating sums with more digits or exponents.
        return Decimal::normalize((float) $value);
    }

    private function withAmountColumns(Builder $query): Builder
    {
        return $query
            ->selectRaw("SUM(CASE WHEN ce.entry_type = 'sale' THEN ce.commission_amount ELSE 0 END) as gross")
            ->selectRaw("SUM(CASE WHEN ce.entry_type <> 'sale' THEN ce.commission_amount ELSE 0 END) as reversals")
            ->selectRaw('SUM(ce.commission_amount) as net')
            ->selectRaw("SUM(CASE WHEN ce.status = 'pending' THEN ce.commission_amount ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN ce.status = 'approved' THEN ce.commission_amount ELSE 0 END) as approved")
            ->selectRaw("SUM(CASE WHEN ce.status = 'paid' THEN ce.commission_amount ELSE 0 END) as paid")
            ->selectRaw('SUM(ce.revenue_ht_snapshot) as revenue')
            ->selectRaw('SUM(ce.cost_total_snapshot) as cost')
            ->selectRaw('SUM(ce.margin_amount_snapshot) as margin');
    }

    /** @return array<string, string|null> */
    private function amountColumns(object $row): array
    {
        $values = [];
        foreach (['gross', 'reversals', 'net', 'pending', 'approved', 'paid', 'revenue', 'cost', 'margin'] as $column) {
            $values[$column] = $this->money($row->{$column} ?? null);
        }
        $values['margin_rate'] = $this->marginRate($values['margin'], $values['revenue']);
        $values['commission_to_margin_ratio'] = $this->commissionToMarginRatio($values['net'], $values['margin']);

        return $values;
    }
}
