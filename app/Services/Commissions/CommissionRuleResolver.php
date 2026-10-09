<?php

namespace App\Services\Commissions;

use App\Models\CommissionRuleSet;
use App\Models\CommissionRuleTier;
use App\Models\Organization;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class CommissionRuleResolver
{
    /** @return array<string, mixed> */
    public function resolve(Organization $organization, CarbonInterface|string $saleDate, ?string $marginRate, ?CommissionRuleSet $candidate = null): array
    {
        if ($marginRate === null) {
            return ['status' => 'not_evaluable', 'rule_set' => null, 'tier' => null, 'commission_rate' => null];
        }

        if ($candidate && (int) $candidate->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('The candidate rule set must belong to the Organization.');
        }

        $ruleSet = $candidate ?: CommissionRuleSet::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', CommissionRuleSet::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $this->date($saleDate))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $this->date($saleDate)))
            ->orderByDesc('effective_from')
            ->first();

        if (! $ruleSet) {
            return ['status' => 'no_rule_set', 'rule_set' => null, 'tier' => null, 'commission_rate' => null];
        }

        $rate = Decimal::normalize($marginRate);
        $tiers = $ruleSet->relationLoaded('tiers') ? $ruleSet->tiers : $ruleSet->tiers()->get();
        /** @var CommissionRuleTier|null $tier */
        $tier = $tiers->first(fn (CommissionRuleTier $tier) =>
            ($tier->min_margin_rate === null || Decimal::compare($rate, $tier->min_margin_rate) >= 0)
            && ($tier->max_margin_rate === null || Decimal::compare($rate, $tier->max_margin_rate) < 0));

        if (! $tier) {
            return ['status' => 'no_matching_tier', 'rule_set' => $this->ruleMetadata($ruleSet), 'tier' => null, 'commission_rate' => null];
        }

        return [
            'status' => 'resolved',
            'rule_set' => $this->ruleMetadata($ruleSet),
            'tier' => [
                'id' => $tier->getKey(),
                'min_margin_rate' => $tier->min_margin_rate,
                'max_margin_rate' => $tier->max_margin_rate,
                'commission_rate' => $tier->commission_rate,
                'sort_order' => $tier->sort_order,
            ],
            'commission_rate' => $tier->commission_rate,
        ];
    }

    /** @return array<string, mixed> */
    private function ruleMetadata(CommissionRuleSet $set): array
    {
        return [
            'id' => $set->getKey(),
            'name' => $set->name,
            'effective_from' => $set->effective_from?->toDateString(),
            'effective_until' => $set->effective_until?->toDateString(),
        ];
    }

    private function date(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : $date;
    }
}
