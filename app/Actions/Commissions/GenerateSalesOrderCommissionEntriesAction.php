<?php

namespace App\Actions\Commissions;

use App\Enums\SalesOrderStatus;
use App\Models\CommissionEntry;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Commissions\CommissionEligibilityService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use LogicException;

final class GenerateSalesOrderCommissionEntriesAction
{
    public function __construct(private readonly CommissionEligibilityService $eligibility, private readonly AuditLogger $audit) {}

    /** @return array{created:int,existing:int,issues:array<string,int>} */
    public function execute(User $actor, SalesOrder $order): array
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Commission entries must be generated inside the Sales Order transaction.');
        }
        if ($order->status !== SalesOrderStatus::Confirmed) {
            throw new LogicException('Commission entries may only be generated for a confirmed Sales Order.');
        }

        $order->loadMissing(['organization', 'store', 'lines']);
        $result = ['created' => 0, 'existing' => 0, 'issues' => []];
        foreach ($order->lines as $line) {
            $sourceKey = $this->sourceKey($line, $order->current_revision_id);
            if (CommissionEntry::query()->where('organization_id', $order->organization_id)->where('source_key', $sourceKey)->exists()) {
                $result['existing']++;
                continue;
            }

            $evaluation = $this->eligibility->evaluate($order->organization, $order, $line);
            if ($evaluation['status'] !== 'resolved') {
                $result['issues'][$evaluation['status']] = ($result['issues'][$evaluation['status']] ?? 0) + 1;
                continue;
            }

            $resolution = $evaluation['resolution'];
            $entry = new CommissionEntry;
            $entry->organization_id = $order->organization_id;
            $entry->store_id = $order->store_id;
            $entry->salesperson_id = $order->salesperson_id;
            $entry->salesperson_name_snapshot = $order->salesperson_name_snapshot;
            $entry->sales_order_id = $order->getKey();
            $entry->sales_order_line_id = $line->getKey();
            $entry->sales_order_revision_id = $order->current_revision_id;
            $entry->entry_type = CommissionEntry::TYPE_SALE;
            $entry->status = CommissionEntry::STATUS_PENDING;
            $entry->source_key = $sourceKey;
            $entry->product_name_snapshot = $line->product_name ?: $line->description;
            $entry->line_reference_snapshot = $line->reference ?: $line->sku;
            $entry->quantity_snapshot = $line->quantity;
            $entry->revenue_ht_snapshot = $line->taxable_amount;
            $entry->purchase_cost_snapshot = $line->purchase_price_snapshot;
            $entry->cost_total_snapshot = $line->cost_total_snapshot;
            $entry->margin_amount_snapshot = $line->margin_amount_snapshot;
            $entry->margin_rate_snapshot = $line->margin_rate_snapshot;
            $entry->commission_rate_snapshot = $resolution['commission_rate'];
            $entry->commission_amount = Decimal::percentage($line->margin_amount_snapshot, $resolution['commission_rate']);
            $entry->commission_rule_set_id = $resolution['rule_set']['id'];
            $entry->commission_rule_set_name_snapshot = $resolution['rule_set']['name'];
            $entry->commission_rule_tier_id = $resolution['tier']['id'];
            $entry->rule_min_margin_snapshot = $resolution['tier']['min_margin_rate'];
            $entry->rule_max_margin_snapshot = $resolution['tier']['max_margin_rate'];
            $entry->sale_date = $order->sale_date;
            $entry->occurred_at = $order->confirmed_at ?? now();
            $entry->metadata = ['order_number' => $order->order_number];
            $entry->save();

            $this->audit->record('commission.entry_created', $actor, $order->organization, $order->store, $entry, newValues: [
                'entry_type' => $entry->entry_type,
                'sales_order_id' => $order->getKey(),
                'sales_order_line_id' => $line->getKey(),
                'salesperson_id' => $entry->salesperson_id,
                'commission_amount' => $entry->commission_amount,
                'commission_rate' => $entry->commission_rate_snapshot,
            ]);
            $result['created']++;
        }

        return $result;
    }

    public function sourceKey(SalesOrderLine $line, ?int $revisionId): string
    {
        return 'sale:line:'.$line->getKey().':revision:'.($revisionId ?? 0);
    }
}
