<?php

namespace App\Services\Commissions;

use App\Enums\SalesOrderStatus;
use App\Models\CommissionRuleSet;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

final class CommissionSimulationService
{
    public function __construct(private readonly CommissionRuleResolver $resolver) {}

    /** @return array<string,mixed> */
    public function simulate(Organization $organization, CommissionRuleSet $ruleSet, string $from, string $to, ?Store $store = null, ?int $salespersonId = null): array
    {
        if ((int) $ruleSet->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The rule set must belong to the Organization.');
        }
        if ($store && (int) $store->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The Store must belong to the Organization.');
        }

        $ruleSet->loadMissing('tiers');
        $totals = $this->emptyTotals();
        $people = [];
        $tiers = [];
        $orderIds = [];

        $sales = DB::table('sales_order_lines as lines')
            ->join('sales_orders as orders', function ($join) {
                $join->on('orders.id', '=', 'lines.sales_order_id')->on('orders.organization_id', '=', 'lines.organization_id');
            })
            ->where('orders.organization_id', $organization->getKey())
            ->where('orders.status', SalesOrderStatus::Confirmed->value)
            ->whereBetween('orders.sale_date', [$from, $to])
            ->when($store, fn ($query) => $query->where('orders.store_id', $store->getKey()))
            ->when($salespersonId !== null, fn ($query) => $query->where('orders.salesperson_id', $salespersonId))
            ->orderBy('lines.id')
            ->select([
                'lines.id', 'lines.taxable_amount', 'lines.margin_amount_snapshot', 'lines.margin_rate_snapshot', 'lines.cost_status',
                'orders.id as order_id', 'orders.sale_date', 'orders.salesperson_id', 'orders.salesperson_name_snapshot',
            ])->cursor();

        foreach ($sales as $line) {
            $orderIds[(int) $line->order_id] = true;
            $this->applyLine($organization, $ruleSet, $line, false, $totals, $people, $tiers);
        }

        // Returns are recognized on received_at, while tier selection remains anchored
        // to the original order sale_date and its immutable salesperson snapshot.
        $returns = DB::table('customer_return_lines as return_lines')
            ->join('customer_returns as returns', function ($join) {
                $join->on('returns.id', '=', 'return_lines.customer_return_id')->on('returns.organization_id', '=', 'return_lines.organization_id');
            })
            ->join('sales_order_lines as source_lines', function ($join) {
                $join->on('source_lines.id', '=', 'return_lines.sales_order_line_id')->on('source_lines.organization_id', '=', 'return_lines.organization_id');
            })
            ->join('sales_orders as source_orders', function ($join) {
                $join->on('source_orders.id', '=', 'source_lines.sales_order_id')->on('source_orders.organization_id', '=', 'source_lines.organization_id');
            })
            ->where('returns.organization_id', $organization->getKey())
            ->where('returns.status', 'received')
            ->whereBetween(DB::raw('DATE(returns.received_at)'), [$from, $to])
            ->when($store, fn ($query) => $query->where('returns.store_id', $store->getKey()))
            ->when($salespersonId !== null, fn ($query) => $query->where('source_orders.salesperson_id', $salespersonId))
            ->orderBy('return_lines.id')
            ->select([
                'return_lines.id', 'return_lines.quantity', 'return_lines.taxable_amount',
                'source_lines.purchase_price_snapshot', 'source_lines.margin_rate_snapshot', 'source_lines.cost_status',
                'source_orders.id as order_id', 'source_orders.sale_date', 'source_orders.salesperson_id', 'source_orders.salesperson_name_snapshot',
            ])->cursor();

        foreach ($returns as $line) {
            $orderIds[(int) $line->order_id] = true;
            $line->margin_amount_snapshot = $line->cost_status === 'available'
                ? Decimal::subtract((string) $line->taxable_amount, Decimal::multiply((string) $line->quantity, (string) $line->purchase_price_snapshot))
                : null;
            $this->applyLine($organization, $ruleSet, $line, true, $totals, $people, $tiers);
        }

        $totals['order_count'] = count($orderIds);
        $totals['salesperson_count'] = count(array_filter(array_keys($people), fn ($key) => $key !== 'unattributed'));
        $totals['estimated_commission_margin_rate'] = Decimal::compare($totals['gross_margin'], '0') === 0
            ? null
            : Decimal::divide(Decimal::multiply($totals['estimated_commission'], '100'), $totals['gross_margin']);

        return [
            ...$totals,
            'by_salesperson' => array_values($people),
            'by_tier' => array_values($tiers),
        ];
    }

    /** @param array<string,mixed> $totals @param array<string,array<string,mixed>> $people @param array<string,array<string,mixed>> $tiers */
    private function applyLine(Organization $organization, CommissionRuleSet $ruleSet, object $line, bool $reversal, array &$totals, array &$people, array &$tiers): void
    {
        if ($line->cost_status !== 'available' || $line->margin_rate_snapshot === null || $line->margin_amount_snapshot === null) {
            $totals['not_evaluable_line_count']++;
            if ($line->cost_status !== 'available') {
                $totals['missing_cost_line_count']++;
            }
            return;
        }

        $resolved = $this->resolver->resolve($organization, (string) $line->sale_date, (string) $line->margin_rate_snapshot, $ruleSet);
        if ($resolved['status'] !== 'resolved') {
            $totals[$resolved['status'].'_line_count']++;
            return;
        }

        $sign = $reversal ? '-1.0000' : '1.0000';
        $revenue = Decimal::multiply((string) $line->taxable_amount, $sign);
        $margin = Decimal::multiply((string) $line->margin_amount_snapshot, $sign);
        $commission = Decimal::percentage($margin, (string) $resolved['commission_rate']);
        $totals['eligible_line_count']++;
        $totals['covered_revenue'] = Decimal::add($totals['covered_revenue'], $revenue);
        $totals['gross_margin'] = Decimal::add($totals['gross_margin'], $margin);
        $totals['estimated_commission'] = Decimal::add($totals['estimated_commission'], $commission);
        if ($line->salesperson_id === null) {
            $totals['unattributed_line_count']++;
        }

        $personKey = $line->salesperson_id === null ? 'unattributed' : (string) $line->salesperson_id;
        $people[$personKey] ??= [
            'salesperson_id' => $line->salesperson_id === null ? null : (int) $line->salesperson_id,
            'salesperson_name' => $line->salesperson_name_snapshot,
            'line_count' => 0, 'covered_revenue' => '0.0000', 'gross_margin' => '0.0000', 'estimated_commission' => '0.0000',
        ];
        $this->accumulate($people[$personKey], $revenue, $margin, $commission);

        $tierKey = (string) $resolved['tier']['id'];
        $tiers[$tierKey] ??= [
            'tier_id' => $resolved['tier']['id'],
            'min_margin_rate' => $resolved['tier']['min_margin_rate'],
            'max_margin_rate' => $resolved['tier']['max_margin_rate'],
            'commission_rate' => $resolved['tier']['commission_rate'],
            'line_count' => 0, 'covered_revenue' => '0.0000', 'gross_margin' => '0.0000', 'estimated_commission' => '0.0000',
        ];
        $this->accumulate($tiers[$tierKey], $revenue, $margin, $commission);
    }

    /** @param array<string,mixed> $bucket */
    private function accumulate(array &$bucket, string $revenue, string $margin, string $commission): void
    {
        $bucket['line_count']++;
        $bucket['covered_revenue'] = Decimal::add($bucket['covered_revenue'], $revenue);
        $bucket['gross_margin'] = Decimal::add($bucket['gross_margin'], $margin);
        $bucket['estimated_commission'] = Decimal::add($bucket['estimated_commission'], $commission);
    }

    /** @return array<string,mixed> */
    private function emptyTotals(): array
    {
        return [
            'eligible_line_count' => 0,
            'not_evaluable_line_count' => 0,
            'missing_cost_line_count' => 0,
            'unattributed_line_count' => 0,
            'no_rule_set_line_count' => 0,
            'no_matching_tier_line_count' => 0,
            'covered_revenue' => '0.0000',
            'gross_margin' => '0.0000',
            'estimated_commission' => '0.0000',
            'estimated_commission_margin_rate' => null,
            'salesperson_count' => 0,
            'order_count' => 0,
        ];
    }
}
