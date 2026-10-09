<?php

namespace App\Actions\Commissions;

use App\Models\CommissionEntry;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class AppendCommissionReversalAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(
        User $actor,
        CommissionEntry $source,
        string $entryType,
        string $sourceKey,
        string $reason,
        ?string $quantity = null,
        ?CustomerReturn $customerReturn = null,
        ?CustomerReturnLine $customerReturnLine = null,
        mixed $occurredAt = null,
    ): ?CommissionEntry {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Commission reversals must be appended inside the source business transaction.');
        }

        $existing = CommissionEntry::query()->where('organization_id', $source->organization_id)->where('source_key', $sourceKey)->first();
        if ($existing) {
            return $existing;
        }

        $source = CommissionEntry::query()->where('organization_id', $source->organization_id)->whereKey($source->getKey())->lockForUpdate()->firstOrFail();
        if ($source->entry_type !== CommissionEntry::TYPE_SALE) {
            throw new LogicException('Only an original sale commission can be reversed.');
        }

        $alreadyReversed = CommissionEntry::query()
            ->where('organization_id', $source->organization_id)
            ->where('source_entry_id', $source->getKey())
            ->lockForUpdate()
            ->get(['quantity_snapshot'])
            ->reduce(fn (string $sum, CommissionEntry $entry) => Decimal::add($sum, ltrim($entry->quantity_snapshot, '-')), '0.0000');
        $remaining = Decimal::subtract($source->quantity_snapshot, $alreadyReversed);
        if (Decimal::compare($remaining, '0') <= 0) {
            return null;
        }

        $reversedQuantity = $quantity === null ? $remaining : Decimal::normalize($quantity);
        if (Decimal::compare($reversedQuantity, '0') <= 0 || Decimal::compare($reversedQuantity, $remaining) > 0) {
            throw ValidationException::withMessages(['commission' => 'La quantité cumulée des reprises de commission dépasse la quantité commissionnée.']);
        }

        $entry = new CommissionEntry;
        $entry->organization_id = $source->organization_id;
        $entry->store_id = $source->store_id;
        $entry->salesperson_id = $source->salesperson_id;
        $entry->salesperson_name_snapshot = $source->salesperson_name_snapshot;
        $entry->sales_order_id = $source->sales_order_id;
        $entry->sales_order_line_id = $source->sales_order_line_id;
        $entry->sales_order_revision_id = $source->sales_order_revision_id;
        $entry->customer_return_id = $customerReturn?->getKey();
        $entry->customer_return_line_id = $customerReturnLine?->getKey();
        $entry->entry_type = $entryType;
        $entry->status = CommissionEntry::STATUS_PENDING;
        $entry->source_entry_id = $source->getKey();
        $entry->source_key = $sourceKey;
        $entry->product_name_snapshot = $source->product_name_snapshot;
        $entry->line_reference_snapshot = $source->line_reference_snapshot;
        $entry->quantity_snapshot = Decimal::multiply($reversedQuantity, '-1');
        $entry->revenue_ht_snapshot = $this->proportion($source->revenue_ht_snapshot, $reversedQuantity, $source->quantity_snapshot);
        $entry->purchase_cost_snapshot = $source->purchase_cost_snapshot;
        $entry->cost_total_snapshot = $this->proportion($source->cost_total_snapshot, $reversedQuantity, $source->quantity_snapshot);
        $entry->margin_amount_snapshot = $this->proportion($source->margin_amount_snapshot, $reversedQuantity, $source->quantity_snapshot);
        $entry->margin_rate_snapshot = $source->margin_rate_snapshot;
        $entry->commission_rate_snapshot = $source->commission_rate_snapshot;
        $entry->commission_amount = $this->proportion($source->commission_amount, $reversedQuantity, $source->quantity_snapshot);
        $entry->commission_rule_set_id = $source->commission_rule_set_id;
        $entry->commission_rule_set_name_snapshot = $source->commission_rule_set_name_snapshot;
        $entry->commission_rule_tier_id = $source->commission_rule_tier_id;
        $entry->rule_min_margin_snapshot = $source->rule_min_margin_snapshot;
        $entry->rule_max_margin_snapshot = $source->rule_max_margin_snapshot;
        $entry->sale_date = $source->sale_date;
        $entry->occurred_at = $occurredAt ?? now();
        $entry->reversal_reason = $reason;
        $entry->metadata = ['source_entry_id' => $source->getKey()];
        $entry->save();

        $this->audit->record('commission.entry_reversed', $actor, $source->organization, $source->store, $entry, newValues: [
            'entry_type' => $entryType,
            'source_entry_id' => $source->getKey(),
            'quantity' => $entry->quantity_snapshot,
            'commission_amount' => $entry->commission_amount,
            'reason' => $reason,
        ]);

        return $entry;
    }

    private function proportion(string $amount, string $quantity, string $sourceQuantity): string
    {
        return Decimal::multiply(Decimal::divide(Decimal::multiply($amount, $quantity), $sourceQuantity), '-1');
    }
}
