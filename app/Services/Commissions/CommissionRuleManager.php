<?php

namespace App\Services\Commissions;

use App\Models\CommissionRuleSet;
use App\Models\CommissionRuleTier;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionRuleManager
{
    public function __construct(private readonly CommissionTierValidator $validator, private readonly AuditLogger $audit) {}

    /** @param list<array<string,mixed>> $tiers */
    public function create(User $actor, Organization $organization, array $data, array $tiers): CommissionRuleSet
    {
        return DB::transaction(function () use ($actor, $organization, $data, $tiers) {
            $set = new CommissionRuleSet;
            $set->organization_id = $organization->getKey();
            $set->name = $data['name'];
            $set->status = CommissionRuleSet::STATUS_DRAFT;
            $set->effective_from = $data['effective_from'] ?? null;
            $set->notes = $data['notes'] ?? null;
            $set->created_by_user_id = $actor->getKey();
            $set->save();
            $this->replaceTiers($set, $tiers);
            $this->audit->record('commission_rule.created', $actor, $organization, auditable: $set, newValues: ['name' => $set->name, 'tier_count' => count($tiers)]);

            return $set->fresh('tiers');
        });
    }

    /** @param list<array<string,mixed>> $tiers */
    public function update(User $actor, CommissionRuleSet $set, array $data, array $tiers): CommissionRuleSet
    {
        $this->requireDraft($set);
        return DB::transaction(function () use ($actor, $set, $data, $tiers) {
            $old = $set->only(['name', 'effective_from', 'notes']);
            $set->name = $data['name'];
            $set->effective_from = $data['effective_from'] ?? null;
            $set->notes = $data['notes'] ?? null;
            $set->save();
            $this->replaceTiers($set, $tiers);
            $this->audit->record('commission_rule.updated', $actor, $set->organization, auditable: $set, oldValues: $old, newValues: ['name' => $set->name, 'effective_from' => $set->effective_from?->toDateString(), 'tier_count' => count($tiers)]);

            return $set->fresh('tiers');
        });
    }

    public function duplicate(User $actor, CommissionRuleSet $source): CommissionRuleSet
    {
        $source->loadMissing('tiers', 'organization');
        return $this->create($actor, $source->organization, [
            'name' => $source->name.' — copie',
            'effective_from' => null,
            'notes' => $source->notes,
        ], $source->tiers->map(fn ($tier) => $tier->only(['min_margin_rate', 'max_margin_rate', 'commission_rate']))->all());
    }

    public function activate(User $actor, CommissionRuleSet $set): CommissionRuleSet
    {
        $this->requireDraft($set);
        if (! $set->effective_from) {
            throw ValidationException::withMessages(['effective_from' => 'La date de prise d’effet est requise pour l’activation.']);
        }
        $this->validator->validate($set->tiers()->orderBy('sort_order')->get()->map(fn ($tier) => $tier->only(['min_margin_rate', 'max_margin_rate', 'commission_rate']))->all());

        return DB::transaction(function () use ($actor, $set) {
            $set = CommissionRuleSet::query()->whereKey($set->getKey())->lockForUpdate()->firstOrFail();
            $this->requireDraft($set);
            $this->validator->validate($set->tiers()->orderBy('sort_order')->get()->map(
                fn ($tier) => $tier->only(['min_margin_rate', 'max_margin_rate', 'commission_rate']),
            )->all());
            $active = CommissionRuleSet::query()
                ->where('organization_id', $set->organization_id)
                ->where('status', CommissionRuleSet::STATUS_ACTIVE)
                ->lockForUpdate()
                ->orderBy('effective_from')
                ->get();
            $start = CarbonImmutable::parse($set->effective_from)->startOfDay();

            foreach ($active as $version) {
                $versionStart = CarbonImmutable::parse($version->effective_from)->startOfDay();
                $versionEnd = $version->effective_until ? CarbonImmutable::parse($version->effective_until)->endOfDay() : null;
                if ($versionStart->greaterThanOrEqualTo($start)) {
                    throw ValidationException::withMessages(['effective_from' => 'La date doit être postérieure au début des versions déjà actives.']);
                }
                if ($versionEnd === null || $versionEnd->greaterThanOrEqualTo($start)) {
                    $version->effective_until = $start->subDay()->toDateString();
                    $version->save();
                }
            }

            $set->status = CommissionRuleSet::STATUS_ACTIVE;
            $set->effective_until = null;
            $set->activated_by_user_id = $actor->getKey();
            $set->activated_at = now();
            $set->save();
            $this->audit->record('commission_rule.activated', $actor, $set->organization, auditable: $set, newValues: [
                'name' => $set->name,
                'effective_from' => $set->effective_from->toDateString(),
                'effective_until' => null,
                'tier_count' => $set->tiers()->count(),
            ]);

            return $set->fresh('tiers');
        });
    }

    public function archive(User $actor, CommissionRuleSet $set): CommissionRuleSet
    {
        $this->requireDraft($set);
        $set->status = CommissionRuleSet::STATUS_ARCHIVED;
        $set->save();
        $this->audit->record('commission_rule.archived', $actor, $set->organization, auditable: $set, newValues: ['name' => $set->name]);
        return $set;
    }

    /** @param list<array<string,mixed>> $tiers */
    private function replaceTiers(CommissionRuleSet $set, array $tiers): void
    {
        $normalized = $this->validator->validate($tiers);
        $set->tiers()->delete();
        foreach ($normalized as $item) {
            $tier = new CommissionRuleTier;
            $tier->organization_id = $set->organization_id;
            $tier->commission_rule_set_id = $set->getKey();
            $tier->min_margin_rate = $item['min_margin_rate'];
            $tier->max_margin_rate = $item['max_margin_rate'];
            $tier->commission_rate = $item['commission_rate'];
            $tier->sort_order = $item['sort_order'];
            $tier->save();
        }
    }

    private function requireDraft(CommissionRuleSet $set): void
    {
        if ($set->status !== CommissionRuleSet::STATUS_DRAFT) {
            throw ValidationException::withMessages(['rule_set' => 'Seule une règle brouillon peut être modifiée.']);
        }
    }
}
