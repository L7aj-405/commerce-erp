<?php

namespace App\Services\Commissions;

use App\Actions\Commissions\GenerateSalesOrderCommissionEntriesAction;
use App\Actions\Commissions\CreateReturnCommissionReversalsAction;
use App\Enums\SalesOrderStatus;
use App\Models\CommissionEntry;
use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionReconciliationService
{
    public function __construct(
        private readonly CommissionEligibilityService $eligibility,
        private readonly GenerateSalesOrderCommissionEntriesAction $generator,
        private readonly CreateReturnCommissionReversalsAction $returnReversals,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{counts:array<string,int>,rows:list<array<string,mixed>>} */
    public function scan(Organization $organization, string $from, string $to, ?Store $store = null, ?int $salespersonId = null, int $limit = 200): array
    {
        $orders = $this->orders($organization, $from, $to, $store, $salespersonId)->limit(500)->get();
        $keys = [];
        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                $keys[] = $this->generator->sourceKey($line, $order->current_revision_id);
            }
        }
        $existing = CommissionEntry::query()->where('organization_id', $organization->getKey())->whereIn('source_key', $keys)->pluck('source_key')->flip();
        $counts = ['eligible_missing' => 0, 'missing_salesperson' => 0, 'missing_cost' => 0, 'margin_unavailable' => 0, 'no_rule_set' => 0, 'no_matching_tier' => 0, 'missing_return_reversal' => 0, 'over_reversed_quantity' => 0];
        $rows = [];

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                $key = $this->generator->sourceKey($line, $order->current_revision_id);
                if ($existing->has($key)) {
                    continue;
                }
                $evaluation = $this->eligibility->evaluate($organization, $order, $line);
                $reason = $evaluation['status'] === 'resolved' ? 'eligible_missing' : $evaluation['status'];
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
                if (count($rows) < $limit) {
                    $rows[] = [
                        'sales_order_id' => $order->getKey(), 'order_number' => $order->order_number, 'sale_date' => $order->sale_date?->toDateString(),
                        'sales_order_line_id' => $line->getKey(), 'product' => $line->product_name ?: $line->description,
                        'salesperson' => $order->salesperson_name_snapshot, 'reason' => $reason,
                    ];
                }
            }
        }

        $returns = CustomerReturn::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'received')
            ->whereIn('sales_order_id', $orders->pluck('id'))
            ->with('lines')
            ->get();
        $ordersById = $orders->keyBy('id');
        foreach ($returns as $return) {
            $returnOrder = $ordersById->get($return->sales_order_id);
            if (! $returnOrder) continue;
            foreach ($return->lines as $line) {
                $source = CommissionEntry::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('sales_order_line_id', $line->sales_order_line_id)
                    ->where('entry_type', CommissionEntry::TYPE_SALE)
                    ->when($returnOrder->current_revision_id === null, fn ($query) => $query->whereNull('sales_order_revision_id'), fn ($query) => $query->where('sales_order_revision_id', $returnOrder->current_revision_id))
                    ->first();
                if (! $source) continue;
                $key = 'return:line:'.$line->getKey().':entry:'.$source->getKey();
                if (! CommissionEntry::query()->where('organization_id', $organization->getKey())->where('source_key', $key)->exists()) {
                    $counts['missing_return_reversal']++;
                }
                $reversed = CommissionEntry::query()->where('organization_id', $organization->getKey())->where('source_entry_id', $source->getKey())->get(['quantity_snapshot'])
                    ->reduce(fn (string $sum, CommissionEntry $entry) => Decimal::add($sum, ltrim($entry->quantity_snapshot, '-')), '0.0000');
                if (Decimal::compare($reversed, $source->quantity_snapshot) > 0) {
                    $counts['over_reversed_quantity']++;
                }
            }
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    /** @return array{orders:int,created:int,reversals_created:int,existing:int,issues:array<string,int>} */
    public function generate(User $actor, Organization $organization, string $from, string $to, ?Store $store = null, ?int $salespersonId = null): array
    {
        return DB::transaction(function () use ($actor, $organization, $from, $to, $store, $salespersonId) {
            $query = $this->orders($organization, $from, $to, $store, $salespersonId);
            if ((clone $query)->count() > 500) {
                throw ValidationException::withMessages(['period' => 'La période contient plus de 500 commandes. Réduisez la période avant la réconciliation.']);
            }
            $orders = $query->lockForUpdate()->get();
            $result = ['orders' => $orders->count(), 'created' => 0, 'reversals_created' => 0, 'existing' => 0, 'issues' => []];
            foreach ($orders as $order) {
                $generated = $this->generator->execute($actor, $order);
                $result['created'] += $generated['created'];
                $result['existing'] += $generated['existing'];
                foreach ($generated['issues'] as $reason => $count) {
                    $result['issues'][$reason] = ($result['issues'][$reason] ?? 0) + $count;
                }
            }
            $returns = CustomerReturn::query()->withoutGlobalScopes()
                ->where('organization_id', $organization->getKey())
                ->where('status', 'received')
                ->whereIn('sales_order_id', $orders->pluck('id'))
                ->with('lines')
                ->lockForUpdate()
                ->get();
            foreach ($returns as $return) {
                $result['reversals_created'] += $this->returnReversals->execute($actor, $return);
            }
            $this->audit->record('commission.reconciliation_run', $actor, $organization, newValues: [
                'from' => $from, 'to' => $to, 'store_id' => $store?->getKey(), 'salesperson_id' => $salespersonId,
                'orders' => $result['orders'], 'created' => $result['created'], 'reversals_created' => $result['reversals_created'], 'existing' => $result['existing'], 'issues' => $result['issues'],
            ]);

            return $result;
        });
    }

    private function orders(Organization $organization, string $from, string $to, ?Store $store, ?int $salespersonId)
    {
        return SalesOrder::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereBetween('sale_date', [$from, $to])
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->when($salespersonId !== null, fn ($query) => $query->where('salesperson_id', $salespersonId))
            ->with(['organization', 'store', 'lines'])
            ->orderBy('id');
    }
}
