<?php

namespace App\Services\Finance;

use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Customer profitability for a period, from the COMMITTED cost snapshots only (sales_order_lines.cost_status / purchase_price_snapshot /
 * cost_total_snapshot / margin_amount_snapshot, frozen at order confirmation) - the same rules as SalesMarginReportService, grouped by
 * customer. No current catalogue cost is ever read.
 *
 *  - Revenue = confirmed sales lines' net HT (line discounts already inside taxable_amount) MINUS received customer returns' HT.
 *  - Cost and margin use ONLY lines whose cost is `available`; returned quantities are costed at the source line's committed unit cost.
 *  - A line without an available cost is never guessed: the customer row is flagged INCOMPLETE and shows how much revenue is covered.
 *  - Margin rate = margin / covered revenue (null when nothing is covered). Orders with no customer record are not listed.
 */
final class CustomerProfitabilityReport
{
    /** @return array{from:string,to:string,rows:list<array<string,mixed>>,totals:array<string,string|int|null>,truncated:bool} */
    public function build(Organization $organization, string $from, string $to, ?Store $store, int $limit = 2000): array
    {
        $orgId = $organization->getKey();
        $sales = DB::table('sales_order_lines as lines')->join('sales_orders as orders', fn ($j) => $j->on('orders.id', '=', 'lines.sales_order_id')->on('orders.organization_id', '=', 'lines.organization_id'))
            ->whereNull('orders.sandbox_id')->where('orders.organization_id', $orgId)->where('orders.status', SalesOrderStatus::Confirmed->value)->whereNotNull('orders.customer_id')
            ->whereBetween('orders.sale_date', [$from, $to])->when($store, fn ($q) => $q->where('orders.store_id', $store->getKey()))
            ->groupBy('orders.customer_id')->select('orders.customer_id')
            ->selectRaw('COALESCE(SUM(lines.taxable_amount), 0) as revenue')
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.taxable_amount ELSE 0 END), 0) as covered")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.cost_total_snapshot ELSE 0 END), 0) as cost")
            ->selectRaw("COALESCE(SUM(CASE WHEN lines.cost_status = 'available' THEN lines.margin_amount_snapshot ELSE 0 END), 0) as margin")
            ->selectRaw("SUM(CASE WHEN lines.cost_status = 'available' THEN 0 ELSE 1 END) as missing")->get()->keyBy('customer_id');

        $returns = DB::table('customer_return_lines as rl')->join('customer_returns as r', fn ($j) => $j->on('r.id', '=', 'rl.customer_return_id')->on('r.organization_id', '=', 'rl.organization_id'))
            ->join('sales_order_lines as sl', fn ($j) => $j->on('sl.id', '=', 'rl.sales_order_line_id')->on('sl.organization_id', '=', 'rl.organization_id'))
            ->join('sales_orders as so', fn ($j) => $j->on('so.id', '=', 'sl.sales_order_id')->on('so.organization_id', '=', 'sl.organization_id'))
            ->whereNull('so.sandbox_id')->where('r.organization_id', $orgId)->where('r.status', 'received')->whereNotNull('so.customer_id')
            ->whereBetween(DB::raw('DATE(r.received_at)'), [$from, $to])->when($store, fn ($q) => $q->where('r.store_id', $store->getKey()))
            ->groupBy('so.customer_id')->select('so.customer_id')
            ->selectRaw('COALESCE(SUM(rl.taxable_amount), 0) as revenue')
            ->selectRaw("COALESCE(SUM(CASE WHEN sl.cost_status = 'available' THEN rl.taxable_amount ELSE 0 END), 0) as covered")
            ->selectRaw("COALESCE(SUM(CASE WHEN sl.cost_status = 'available' THEN ROUND(rl.quantity * sl.purchase_price_snapshot, 4) ELSE 0 END), 0) as cost")
            ->selectRaw("SUM(CASE WHEN sl.cost_status = 'available' THEN 0 ELSE 1 END) as missing")->get()->keyBy('customer_id');

        $ids = $sales->keys()->merge($returns->keys())->unique()->values();
        $truncated = $ids->count() > $limit;
        $names = DB::table('customers')->where('organization_id', $orgId)->whereIn('id', $ids->take($limit)->all())->pluck('display_name', 'id');
        $rows = [];
        $total = ['revenue' => '0.0000', 'covered_revenue' => '0.0000', 'cost' => '0.0000', 'margin' => '0.0000', 'missing_cost_lines' => 0];
        foreach ($ids->take($limit) as $id) {
            $s = $sales[$id] ?? null;
            $r = $returns[$id] ?? null;
            $revenue = Decimal::subtract($this->n($s->revenue ?? 0), $this->n($r->revenue ?? 0));
            $covered = Decimal::subtract($this->n($s->covered ?? 0), $this->n($r->covered ?? 0));
            $cost = Decimal::subtract($this->n($s->cost ?? 0), $this->n($r->cost ?? 0));
            $returnMargin = Decimal::subtract($this->n($r->covered ?? 0), $this->n($r->cost ?? 0));
            $margin = Decimal::subtract($this->n($s->margin ?? 0), $returnMargin);
            $missing = (int) ($s->missing ?? 0) + (int) ($r->missing ?? 0);
            $rate = Decimal::compare($covered, '0') > 0 ? Decimal::divide($margin, $covered, 4) : null;
            $rows[] = ['customer_id' => (int) $id, 'customer' => $names[$id] ?? '—', 'revenue' => $revenue, 'covered_revenue' => $covered, 'cost' => $cost, 'margin' => $margin,
                'margin_rate' => $rate, 'missing_cost_lines' => $missing, 'incomplete' => $missing > 0];
            foreach (['revenue' => $revenue, 'covered_revenue' => $covered, 'cost' => $cost, 'margin' => $margin] as $k => $v) {
                $total[$k] = Decimal::add($total[$k], $v);
            }
            $total['missing_cost_lines'] += $missing;
        }
        usort($rows, fn ($a, $b) => Decimal::compare($b['margin'], $a['margin']) ?: strcmp($a['customer'], $b['customer']));
        $total['margin_rate'] = Decimal::compare($total['covered_revenue'], '0') > 0 ? Decimal::divide($total['margin'], $total['covered_revenue'], 4) : null;
        $total['incomplete'] = $total['missing_cost_lines'] > 0 ? 1 : 0;

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $total, 'truncated' => $truncated];
    }

    private function n(mixed $value): string
    {
        return is_float($value) ? Decimal::normalize(sprintf('%.4F', $value)) : Decimal::normalize((string) bcadd((string) $value, '0', 4));
    }
}
