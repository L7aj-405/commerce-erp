<?php

namespace App\Services\Finance;

use App\Models\FinanceExpectedCollection;
use App\Models\FinanceObligation;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;

class FinanceTreasuryForecastService
{
    private const OBLIGATION_PAID = "COALESCE((SELECT SUM(a.amount) FROM finance_outgoing_payment_allocations a INNER JOIN finance_outgoing_payments p ON p.id=a.outgoing_payment_id AND p.organization_id=a.organization_id WHERE a.obligation_id=finance_obligations.id AND a.organization_id=finance_obligations.organization_id AND p.status='posted'),0)";
    private const MATCHED = "COALESCE((SELECT SUM(m.amount) FROM finance_expected_collection_matches m WHERE m.expected_collection_id=finance_expected_collections.id AND m.organization_id=finance_expected_collections.organization_id),0)";

    public function __construct(private readonly FinancialAccountBalanceService $balances) {}

    /** @return array<string,mixed> */
    public function forecast(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $today = now()->startOfDay();
        $past = $period->end->lt($today);
        $balanceDate = $past ? $period->end : $today;
        $position = $this->balances->organizationPosition($organization, $balanceDate);
        if ($past) {
            return ['mode' => 'historical', 'balance' => $position, 'expected' => '0.0000', 'remaining_outflows' => '0.0000',
                'overdue' => '0.0000', 'projected' => null, 'shortage' => null, 'currency_code' => $position['currency_code']];
        }

        $start = $today->toDateString();
        $end = $period->end->toDateString();
        $expected = FinanceExpectedCollection::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('state', 'planned')->whereBetween('expected_date', [$start, $end])
            ->when($store, fn ($query) => $query->where('store_id', $store->id))
            ->selectRaw('COALESCE(SUM(expected_amount - '.self::MATCHED.'),0) as amount')->value('amount');

        $outflowQuery = FinanceObligation::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->whereNull('cancelled_at')->whereDate('due_date', '<=', $end)
            ->when($store, fn ($query) => $query->where('store_id', $store->id))
            ->whereRaw(self::OBLIGATION_PAID.' < planned_amount');
        $remaining = (clone $outflowQuery)->selectRaw('COALESCE(SUM(planned_amount - '.self::OBLIGATION_PAID.'),0) as amount')->value('amount');
        $overdue = (clone $outflowQuery)->whereDate('due_date', '<', $start)
            ->selectRaw('COALESCE(SUM(planned_amount - '.self::OBLIGATION_PAID.'),0) as amount')->value('amount');
        $expected = Decimal::normalize((string) $expected);
        $remaining = Decimal::normalize((string) $remaining);
        $projected = $position['complete'] ? Decimal::subtract(Decimal::add($position['total'], $expected), $remaining) : null;
        $shortage = $projected !== null && Decimal::compare($projected, '0.0000') < 0 ? Decimal::subtract('0.0000', $projected) : ($projected === null ? null : '0.0000');

        return ['mode' => $period->start->gt($today) ? 'future' : 'current', 'balance' => $position, 'expected' => $expected,
            'remaining_outflows' => $remaining, 'overdue' => Decimal::normalize((string) $overdue), 'projected' => $projected,
            'shortage' => $shortage, 'currency_code' => $position['currency_code']];
    }

    /** @return array<int,array<string,mixed>> */
    public function expectedRows(Organization $organization, FinancePeriod $period, ?Store $store, ?int $limit = 100): array
    {
        $query = FinanceExpectedCollection::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->whereBetween('expected_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->when($store, fn ($query) => $query->where('store_id', $store->id))
            ->with(['salesOrder:id,organization_id,store_id,order_number,customer_id,total_incl_tax,currency_code', 'salesOrder.customer:id,display_name,company_name'])
            ->withSum('matches as matched_amount', 'amount')->orderBy('expected_date');
        if ($limit !== null) $query->limit($limit);

        return $query->get()
            ->map(function (FinanceExpectedCollection $row) {
                $matched = Decimal::normalize((string) ($row->matched_amount ?? '0'));
                $remaining = Decimal::subtract((string) $row->expected_amount, $matched);
                $status = $row->state === 'cancelled' ? 'cancelled' : (Decimal::compare($remaining, '0.0000') <= 0 ? 'received' : (Decimal::compare($matched, '0.0000') > 0 ? 'partial' : ($row->expected_date->lt(now()->startOfDay()) ? 'overdue' : 'planned')));
                return ['id' => $row->id, 'sales_order_id' => $row->sales_order_id, 'order_number' => $row->salesOrder->order_number,
                    'customer' => $row->salesOrder->customer?->company_name ?: $row->salesOrder->customer?->display_name ?: '—', 'label' => $row->label,
                    'reference' => $row->reference, 'expected_date' => $row->expected_date->toDateString(), 'amount' => (string) $row->expected_amount,
                    'matched' => $matched, 'remaining' => $remaining, 'currency_code' => $row->currency_code, 'status' => $status, 'notes' => $row->notes];
            })->all();
    }
}
