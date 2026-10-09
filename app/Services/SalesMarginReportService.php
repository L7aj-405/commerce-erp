<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Models\CustomerReturnLine;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SalesMarginReportService
{
    /**
     * Stored-snapshot summary for one order. No current catalog cost is read.
     *
     * @return array<string, int|string|null>
     */
    public function forOrder(SalesOrder $order): array
    {
        $lines = $order->relationLoaded('lines') ? $order->lines : $order->lines()->get();
        $revenue = '0.0000';
        $coveredRevenue = '0.0000';
        $cost = '0.0000';
        $margin = '0.0000';
        $missing = 0;

        foreach ($lines as $line) {
            $revenue = Decimal::add($revenue, $line->taxable_amount);
            if ($line->cost_status !== 'available') {
                $missing++;
                continue;
            }
            $coveredRevenue = Decimal::add($coveredRevenue, $line->taxable_amount);
            $cost = Decimal::add($cost, $line->cost_total_snapshot);
            $margin = Decimal::add($margin, $line->margin_amount_snapshot);
        }

        return $this->summary($revenue, $coveredRevenue, $cost, $margin, $missing);
    }

    /**
     * Organization/date/store aggregate using committed line snapshots only.
     * Sales use sale_date; received returns use their received_at business event.
     *
     * @return array<string, int|string|null>
     */
    public function forPeriod(
        Organization $organization,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?Store $store = null,
        ?int $salespersonId = null,
        bool $unattributedOnly = false,
    ): array
    {
        if ($store && (int) $store->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The Store must belong to the reporting Organization.');
        }

        $sales = DB::table('sales_order_lines as lines')
            ->join('sales_orders as orders', function ($join) {
                $join->on('orders.id', '=', 'lines.sales_order_id')
                    ->on('orders.organization_id', '=', 'lines.organization_id');
            })
            ->where('orders.organization_id', $organization->getKey())
            ->where('orders.status', SalesOrderStatus::Confirmed->value)
            ->whereBetween('orders.sale_date', [$this->date($from), $this->date($to)])
            ->when($store, fn ($query) => $query->where('orders.store_id', $store->getKey()))
            ->when($unattributedOnly, fn ($query) => $query->whereNull('orders.salesperson_id'))
            ->when(! $unattributedOnly && $salespersonId !== null, fn ($query) => $query->where('orders.salesperson_id', $salespersonId))
            ->selectRaw("COALESCE(SUM(lines.taxable_amount), 0) as revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.taxable_amount ELSE 0 END), 0) as covered_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.cost_total_snapshot ELSE 0 END), 0) as cost_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.margin_amount_snapshot ELSE 0 END), 0) as margin_amount")
            ->selectRaw("SUM(CASE WHEN lines.cost_status = 'available' THEN 0 ELSE 1 END) as missing_count")
            ->first();

        $returns = DB::table('customer_return_lines as return_lines')
            ->join('customer_returns as returns', function ($join) {
                $join->on('returns.id', '=', 'return_lines.customer_return_id')
                    ->on('returns.organization_id', '=', 'return_lines.organization_id');
            })
            ->join('sales_order_lines as source_lines', function ($join) {
                $join->on('source_lines.id', '=', 'return_lines.sales_order_line_id')
                    ->on('source_lines.organization_id', '=', 'return_lines.organization_id');
            })
            ->join('sales_orders as source_orders', function ($join) {
                $join->on('source_orders.id', '=', 'source_lines.sales_order_id')
                    ->on('source_orders.organization_id', '=', 'source_lines.organization_id');
            })
            ->where('returns.organization_id', $organization->getKey())
            ->where('returns.status', 'received')
            ->whereBetween(DB::raw('DATE(returns.received_at)'), [$this->date($from), $this->date($to)])
            ->when($store, fn ($query) => $query->where('returns.store_id', $store->getKey()))
            ->when($unattributedOnly, fn ($query) => $query->whereNull('source_orders.salesperson_id'))
            ->when(! $unattributedOnly && $salespersonId !== null, fn ($query) => $query->where('source_orders.salesperson_id', $salespersonId))
            ->selectRaw("COALESCE(SUM(return_lines.taxable_amount), 0) as revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN source_lines.cost_status = 'available' THEN return_lines.taxable_amount ELSE 0 END), 0) as covered_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN source_lines.cost_status = 'available' THEN return_lines.quantity * source_lines.purchase_price_snapshot ELSE 0 END), 0) as cost_total")
            ->selectRaw("SUM(CASE WHEN source_lines.cost_status = 'available' THEN 0 ELSE 1 END) as missing_count")
            ->first();

        $returnMargin = Decimal::subtract((string) $returns->covered_revenue, (string) $returns->cost_total);
        $gross = $this->summary(
            (string) $sales->revenue,
            (string) $sales->covered_revenue,
            (string) $sales->cost_total,
            (string) $sales->margin_amount,
            (int) $sales->missing_count,
        );
        $returnEffect = $this->summary(
            (string) $returns->revenue,
            (string) $returns->covered_revenue,
            (string) $returns->cost_total,
            $returnMargin,
            (int) $returns->missing_count,
        );

        return [
            ...$this->summary(
                Decimal::subtract($gross['net_revenue_excl_tax'], $returnEffect['net_revenue_excl_tax']),
                Decimal::subtract($gross['covered_revenue_excl_tax'], $returnEffect['covered_revenue_excl_tax']),
                Decimal::subtract($gross['cost_total'], $returnEffect['cost_total']),
                Decimal::subtract($gross['gross_margin_amount'], $returnEffect['gross_margin_amount']),
                $gross['missing_cost_line_count'] + $returnEffect['missing_cost_line_count'],
            ),
            'sales' => $gross,
            'returns' => $returnEffect,
        ];
    }

    /**
     * Two aggregate queries (sales + received returns), never one query per
     * employee. The null key is returned as the explicit unattributed bucket.
     *
     * @return list<array<string, int|string|null>>
     */
    public function bySalesperson(Organization $organization, CarbonInterface|string $from, CarbonInterface|string $to, ?Store $store = null): array
    {
        if ($store && (int) $store->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The Store must belong to the reporting Organization.');
        }

        $sales = DB::table('sales_order_lines as lines')
            ->join('sales_orders as orders', function ($join) {
                $join->on('orders.id', '=', 'lines.sales_order_id')
                    ->on('orders.organization_id', '=', 'lines.organization_id');
            })
            ->where('orders.organization_id', $organization->getKey())
            ->where('orders.status', SalesOrderStatus::Confirmed->value)
            ->whereBetween('orders.sale_date', [$this->date($from), $this->date($to)])
            ->when($store, fn ($query) => $query->where('orders.store_id', $store->getKey()))
            ->groupBy('orders.salesperson_id', 'orders.salesperson_name_snapshot')
            ->select(['orders.salesperson_id', 'orders.salesperson_name_snapshot'])
            ->selectRaw("COALESCE(SUM(lines.taxable_amount), 0) as revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.taxable_amount ELSE 0 END), 0) as covered_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.cost_total_snapshot ELSE 0 END), 0) as cost_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.margin_amount_snapshot ELSE 0 END), 0) as margin_amount")
            ->selectRaw("SUM(CASE WHEN lines.cost_status = 'available' THEN 0 ELSE 1 END) as missing_count")
            ->get();

        $returns = DB::table('customer_return_lines as return_lines')
            ->join('customer_returns as returns', function ($join) {
                $join->on('returns.id', '=', 'return_lines.customer_return_id')
                    ->on('returns.organization_id', '=', 'return_lines.organization_id');
            })
            ->join('sales_order_lines as source_lines', function ($join) {
                $join->on('source_lines.id', '=', 'return_lines.sales_order_line_id')
                    ->on('source_lines.organization_id', '=', 'return_lines.organization_id');
            })
            ->join('sales_orders as source_orders', function ($join) {
                $join->on('source_orders.id', '=', 'source_lines.sales_order_id')
                    ->on('source_orders.organization_id', '=', 'source_lines.organization_id');
            })
            ->where('returns.organization_id', $organization->getKey())
            ->where('returns.status', 'received')
            ->whereBetween(DB::raw('DATE(returns.received_at)'), [$this->date($from), $this->date($to)])
            ->when($store, fn ($query) => $query->where('returns.store_id', $store->getKey()))
            ->groupBy('source_orders.salesperson_id', 'source_orders.salesperson_name_snapshot')
            ->select(['source_orders.salesperson_id', 'source_orders.salesperson_name_snapshot'])
            ->selectRaw("COALESCE(SUM(return_lines.taxable_amount), 0) as revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN source_lines.cost_status = 'available' THEN return_lines.taxable_amount ELSE 0 END), 0) as covered_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN source_lines.cost_status = 'available' THEN return_lines.quantity * source_lines.purchase_price_snapshot ELSE 0 END), 0) as cost_total")
            ->selectRaw("SUM(CASE WHEN source_lines.cost_status = 'available' THEN 0 ELSE 1 END) as missing_count")
            ->get();

        $buckets = [];
        foreach ($sales as $row) {
            $key = $row->salesperson_id === null ? 'unattributed' : (string) $row->salesperson_id;
            $buckets[$key] = [
                'salesperson_id' => $row->salesperson_id === null ? null : (int) $row->salesperson_id,
                'salesperson_name' => $row->salesperson_name_snapshot,
                'sales' => $this->summary((string) $row->revenue, (string) $row->covered_revenue, (string) $row->cost_total, (string) $row->margin_amount, (int) $row->missing_count),
                'returns' => $this->summary('0', '0', '0', '0', 0),
            ];
        }
        foreach ($returns as $row) {
            $key = $row->salesperson_id === null ? 'unattributed' : (string) $row->salesperson_id;
            $buckets[$key] ??= [
                'salesperson_id' => $row->salesperson_id === null ? null : (int) $row->salesperson_id,
                'salesperson_name' => $row->salesperson_name_snapshot,
                'sales' => $this->summary('0', '0', '0', '0', 0),
                'returns' => $this->summary('0', '0', '0', '0', 0),
            ];
            $buckets[$key]['returns'] = $this->summary(
                (string) $row->revenue,
                (string) $row->covered_revenue,
                (string) $row->cost_total,
                Decimal::subtract((string) $row->covered_revenue, (string) $row->cost_total),
                (int) $row->missing_count,
            );
        }

        return collect($buckets)->map(function (array $bucket) {
            $sales = $bucket['sales'];
            $returns = $bucket['returns'];

            return [
                'salesperson_id' => $bucket['salesperson_id'],
                'salesperson_name' => $bucket['salesperson_name'],
                ...$this->summary(
                    Decimal::subtract($sales['net_revenue_excl_tax'], $returns['net_revenue_excl_tax']),
                    Decimal::subtract($sales['covered_revenue_excl_tax'], $returns['covered_revenue_excl_tax']),
                    Decimal::subtract($sales['cost_total'], $returns['cost_total']),
                    Decimal::subtract($sales['gross_margin_amount'], $returns['gross_margin_amount']),
                    $sales['missing_cost_line_count'] + $returns['missing_cost_line_count'],
                ),
            ];
        })->sortBy(fn (array $bucket) => $bucket['salesperson_name'] ?? '')->values()->all();
    }

    /** @return array{net_revenue_excl_tax:string, covered_revenue_excl_tax:string, cost_total:string, gross_margin_amount:string, gross_margin_rate:?string, missing_cost_line_count:int} */
    public function returnLineEffect(CustomerReturnLine $line): array
    {
        $source = $line->relationLoaded('salesOrderLine') ? $line->salesOrderLine : $line->salesOrderLine()->firstOrFail();
        if ($source->cost_status !== 'available') {
            return $this->summary((string) $line->taxable_amount, '0', '0', '0', 1);
        }

        $cost = Decimal::multiply($line->quantity, $source->purchase_price_snapshot);

        return $this->summary(
            (string) $line->taxable_amount,
            (string) $line->taxable_amount,
            $cost,
            Decimal::subtract($line->taxable_amount, $cost),
            0,
        );
    }

    /** @return array{net_revenue_excl_tax:string, covered_revenue_excl_tax:string, cost_total:string, gross_margin_amount:string, gross_margin_rate:?string, missing_cost_line_count:int} */
    private function summary(string $revenue, string $coveredRevenue, string $cost, string $margin, int $missing): array
    {
        $revenue = Decimal::normalize($revenue);
        $coveredRevenue = Decimal::normalize($coveredRevenue);
        $cost = Decimal::normalize($cost);
        $margin = Decimal::normalize($margin);

        return [
            'net_revenue_excl_tax' => $revenue,
            'covered_revenue_excl_tax' => $coveredRevenue,
            'cost_total' => $cost,
            'gross_margin_amount' => $margin,
            'gross_margin_rate' => Decimal::compare($coveredRevenue, '0') === 0
                ? null
                : Decimal::divide(Decimal::multiply($margin, '100'), $coveredRevenue),
            'missing_cost_line_count' => $missing,
        ];
    }

    private function date(CarbonInterface|string $value): string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }
}
