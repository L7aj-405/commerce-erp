<?php

namespace App\Services\Dashboards;

use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\Store;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinanceMonthlySituationService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReceivablesService;
use App\Services\Finance\FinanceTreasuryForecastService;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ExecutiveDashboardService
{
    public function __construct(
        private readonly FinanceMonthlyReportService $reports,
        private readonly FinanceReceivablesService $receivables,
        private readonly FinanceTreasuryForecastService $treasury,
        private readonly FinanceMonthlySituationService $obligations,
    ) {}

    /** @return array<string,mixed> */
    public function main(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $current = $this->reports->situation($organization, $period, $store);
        $previousPeriod = FinancePeriod::fromMonth($period->start->copy()->subMonthNoOverflow()->format('Y-m'));
        $previous = $this->reports->situation($organization, $previousPeriod, $store);
        $receivables = $this->receivables->salesOrderReceivablesAsOf($organization, $period->end, $store, 1);
        $orderCount = $this->confirmedOrderCount($organization, $period, $store);
        $previousOrderCount = $this->confirmedOrderCount($organization, $previousPeriod, $store);
        $states = $this->orderStates($organization, $period, $store);
        $returns = $this->receivedReturns($organization, $period, $store);
        $outOfStock = $this->outOfStockCount($organization, $store);

        return [
            'period' => ['month' => $period->month, 'label' => $period->label()],
            'primary' => [
                'net_sales' => $this->kpi($current['ventes_nettes'], $previous['ventes_nettes']),
                'net_collections' => $this->kpi($current['net_encaisse'], $previous['net_encaisse']),
                'orders' => $this->countKpi($orderCount, $previousOrderCount),
                'receivables' => ['value' => $receivables['total'], 'variation' => null],
            ],
            'secondary' => [
                'average_basket' => $orderCount > 0 ? Decimal::divide($current['ventes'], (string) $orderCount) : '0.0000',
                'partial_orders' => $states['partially_paid'],
                'unpaid_orders' => $states['unpaid'],
                'refunds' => $current['remboursements'],
                'returns' => $returns,
                'out_of_stock' => $outOfStock,
            ],
            'charts' => [
                'sales_vs_collections' => $this->salesVsCollections($organization, $period, $store),
                'order_states' => $states,
                'top_products' => $this->topProducts($organization, $period, $store),
            ],
            'attention' => [
                ['label' => 'Créances clients', 'value' => $receivables['total'], 'kind' => 'money', 'href' => '/finance/creances'],
                ['label' => 'Commandes partiellement payées', 'value' => $receivables['status_counts']['partially_paid'], 'kind' => 'count', 'href' => '/sales/orders?payment_status=partially_paid'],
                ['label' => 'Commandes non payées', 'value' => $receivables['status_counts']['unpaid'], 'kind' => 'count', 'href' => '/sales/orders?payment_status=unpaid'],
                ['label' => 'Articles à cataloguer', 'value' => $outOfStock, 'kind' => 'count', 'href' => '/procurement/out-of-stock-articles'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function finance(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $situation = $this->reports->situation($organization, $period, $store);
        $receivables = $this->receivables->salesOrderReceivablesAsOf($organization, $period->end, $store, 1);
        $treasury = $this->treasury->forecast($organization, $period, $store);
        $obligations = $this->obligations->kpis($organization, $period, $store);

        return [
            'situation' => $situation,
            'primary' => [
                'net_collections' => $situation['net_encaisse'],
                'receivables' => $receivables['total'],
                'treasury_balance' => $treasury['balance']['complete'] ? $treasury['balance']['total'] : null,
                'treasury_projected' => $treasury['projected'],
            ],
            'secondary' => [
                'net_sales' => $situation['ventes_nettes'],
                'net_billing' => $situation['facturation'],
                'refunds' => $situation['remboursements'],
                'remaining_obligations' => $obligations['remaining'],
                'expected_collections' => $treasury['expected'],
                'overdue' => $obligations['overdue'],
                'shortage' => $treasury['shortage'],
            ],
            'charts' => [
                'sales_vs_collections' => $this->salesVsCollections($organization, $period, $store),
                'cash_flow' => $this->cashFlow($organization, $period, $store),
                'receivables' => [
                    'counts' => $receivables['status_counts'],
                    'amounts' => $receivables['status_amounts'],
                ],
                'obligations' => $this->obligations->remainingByCategory($organization, $period, $store),
            ],
            'treasury' => $treasury,
        ];
    }

    /** @return array<int,array{date:string,label:string,sales:string,collections:string}> */
    private function salesVsCollections(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $sales = $this->dailyTotals('sales_orders', 'sale_date', 'total_incl_tax', $organization, $period, $store,
            fn (Builder $query) => $query->where('status', SalesOrderStatus::Confirmed->value));
        $credits = $this->dailyTotals('credit_notes', 'credit_note_date', 'total_incl_tax', $organization, $period, $store,
            fn (Builder $query) => $query->where('status', 'issued'));
        $collections = $this->dailyTotals('payments', 'payment_date', 'amount', $organization, $period, $store,
            fn (Builder $query) => $query->where('status', PaymentStatus::Posted->value));
        $refunds = $this->dailyTotals('payment_refunds', 'refund_date', 'amount', $organization, $period, $store,
            fn (Builder $query) => $query->where('status', 'posted'));

        return $this->days($period)->map(fn (Carbon $day) => [
            'date' => $day->toDateString(),
            'label' => $day->locale('fr')->translatedFormat('j M'),
            'sales' => Decimal::subtract($sales[$day->toDateString()] ?? '0.0000', $credits[$day->toDateString()] ?? '0.0000'),
            'collections' => Decimal::subtract($collections[$day->toDateString()] ?? '0.0000', $refunds[$day->toDateString()] ?? '0.0000'),
        ])->all();
    }

    /** @return array<int,array{date:string,label:string,incoming:string,outgoing:string}> */
    private function cashFlow(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $incoming = $this->dailyTotals('payments', 'payment_date', 'amount', $organization, $period, $store,
            fn (Builder $query) => $query->where('status', PaymentStatus::Posted->value));
        $refunds = $this->dailyTotals('payment_refunds', 'refund_date', 'amount', $organization, $period, $store,
            fn (Builder $query) => $query->where('status', 'posted'));
        $outgoing = $this->dailyTotals('finance_outgoing_payments', 'payment_date', 'amount', $organization, $period, $store,
            fn (Builder $query) => $query->where('status', 'posted'));

        return $this->days($period)->map(fn (Carbon $day) => [
            'date' => $day->toDateString(),
            'label' => $day->locale('fr')->translatedFormat('j M'),
            'incoming' => $incoming[$day->toDateString()] ?? '0.0000',
            'outgoing' => Decimal::add($outgoing[$day->toDateString()] ?? '0.0000', $refunds[$day->toDateString()] ?? '0.0000'),
        ])->all();
    }

    /** @return array<string,string> */
    private function dailyTotals(string $table, string $dateColumn, string $amountColumn, Organization $organization, FinancePeriod $period, ?Store $store, callable $scope): array
    {
        $query = DB::table($table)->where('organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->whereDate($dateColumn, '>=', $period->start->toDateString())
            ->whereDate($dateColumn, '<=', $period->end->toDateString());
        $scope($query);

        return $query->selectRaw("DATE({$dateColumn}) as bucket, SUM({$amountColumn}) as aggregate")
            ->groupByRaw("DATE({$dateColumn})")
            ->pluck('aggregate', 'bucket')
            ->map(fn ($value) => Decimal::normalize((string) $value))
            ->all();
    }

    /** @return array{paid:int,partially_paid:int,unpaid:int} */
    private function orderStates(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $rows = DB::table('sales_orders')->where('organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereBetween('sale_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('payment_status')->select('payment_status')->selectRaw('COUNT(*) as aggregate')
            ->pluck('aggregate', 'payment_status');

        return [
            'paid' => (int) ($rows['paid'] ?? 0),
            'partially_paid' => (int) ($rows['partially_paid'] ?? 0),
            'unpaid' => (int) ($rows['unpaid'] ?? 0),
        ];
    }

    /** @return array<int,array{label:string,value:string,quantity:string}> */
    private function topProducts(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        return DB::table('sales_order_lines as line')
            ->join('sales_orders as orders', function ($join) {
                $join->on('orders.id', '=', 'line.sales_order_id')->on('orders.organization_id', '=', 'line.organization_id');
            })
            ->where('orders.organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('orders.store_id', $store->getKey()))
            ->where('orders.status', SalesOrderStatus::Confirmed->value)
            ->where('line.line_type', 'catalog')
            ->whereBetween('orders.sale_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy(['line.product_variant_id', 'line.product_name', 'line.variant_name'])
            ->addSelect(['line.product_name', 'line.variant_name'])
            ->selectRaw('SUM(line.total_incl_tax) as value, SUM(line.quantity) as quantity')
            ->orderByDesc('value')->limit(5)->get()
            ->map(fn ($row) => [
                'label' => trim($row->product_name.($row->variant_name ? ' · '.$row->variant_name : '')),
                'value' => Decimal::normalize((string) $row->value),
                'quantity' => Decimal::normalize((string) $row->quantity),
            ])
            ->all();
    }

    /** @return array{count:int,amount:string} */
    private function receivedReturns(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $row = DB::table('customer_returns')->where('organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->where('status', 'received')->whereBetween('received_at', [$period->start, $period->end])
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(total_incl_tax), 0) as aggregate_amount')->first();

        return ['count' => (int) $row->aggregate_count, 'amount' => Decimal::normalize((string) $row->aggregate_amount)];
    }

    private function confirmedOrderCount(Organization $organization, FinancePeriod $period, ?Store $store): int
    {
        return DB::table('sales_orders')->where('organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereBetween('sale_date', [$period->start->toDateString(), $period->end->toDateString()])->count();
    }

    private function outOfStockCount(Organization $organization, ?Store $store): int
    {
        return DB::table('out_of_stock_articles')->where('organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->where('status', 'unresolved')->count();
    }

    /** @return array{value:string,variation:?string} */
    private function kpi(string $current, string $previous): array
    {
        return ['value' => $current, 'variation' => $this->variation($current, $previous)];
    }

    /** @return array{value:int,variation:?string} */
    private function countKpi(int $current, int $previous): array
    {
        return ['value' => $current, 'variation' => $previous === 0 ? null : Decimal::divide(Decimal::multiply((string) ($current - $previous), '100'), (string) $previous, 1)];
    }

    private function variation(string $current, string $previous): ?string
    {
        if (Decimal::compare($previous, '0.0000') === 0) {
            return null;
        }

        return Decimal::divide(Decimal::multiply(Decimal::subtract($current, $previous), '100'), $previous, 1);
    }

    private function days(FinancePeriod $period)
    {
        return collect(range(0, $period->start->diffInDays($period->end)))
            ->map(fn (int $offset) => $period->start->copy()->addDays($offset));
    }
}
