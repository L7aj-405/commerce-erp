<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * W4.3 single source of attention ownership (tenant/business only).
 *
 * A valid owner is an active member of the organization whose role grants
 * quotations.view and who belongs to the item's store — exactly who can see
 * the item (AttentionQuery::canView). Owner ≠ quotation creator/issuer ≠
 * follow-up assignee ≠ organization owner ≠ platform operator.
 *
 * Background repair (each scan, per active item, row-locked, skipped if the
 * row's version changed meanwhile — a person's action always wins):
 * 1. initial owner, once per episode: the quotation's issuer, else its
 *    creator, if valid; otherwise unassigned;
 * 2. owner no longer valid (left, suspended, lost quotations.view or the
 *    store) → unassigned;
 * 3. an unassigned CRITICAL item — unless a manager deliberately unassigned
 *    it — gets one deterministic fallback manager: a valid owner who also
 *    holds follow_ups.manage_all, lowest user id. No store-manager concept
 *    exists, platform capabilities (system.health.view) play no role, and
 *    nobody qualifying means it stays unassigned. Warnings are not forced.
 */
final class AttentionOwnership
{
    public const SOURCE_ISSUER = 'issuer';
    public const SOURCE_CREATOR = 'creator';
    public const SOURCE_FALLBACK = 'fallback';
    public const SOURCE_CLAIM = 'claim';
    public const SOURCE_MANUAL = 'manual';

    private const CHUNK = 200;

    /** The subset of `$userIds` who may own items of `$storeId` (one query). @param list<int> $userIds @return list<int> */
    public function eligibleUserIds(Organization $organization, ?int $storeId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        return $this->eligibleQuery($organization, $storeId)->whereIn('users.id', $userIds)
            ->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public function isValidOwner(Organization $organization, AttentionItem $item, ?int $userId): bool
    {
        return $userId !== null && $this->eligibleUserIds($organization, $item->store_id, [$userId]) !== [];
    }

    /** One deterministic manager for the store (lowest user id), optionally excluding a user. */
    public function fallbackManager(Organization $organization, ?int $storeId, ?int $exceptUserId = null): ?int
    {
        $id = $this->eligibleQuery($organization, $storeId)
            ->whereHas('organizationMemberships', fn (Builder $membership) => $membership
                ->where('organization_id', $organization->getKey())->where('status', 'active')
                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('key', 'follow_ups.manage_all')))
            ->when($exceptUserId !== null, fn (Builder $query) => $query->where('users.id', '!=', $exceptUserId))
            ->orderBy('users.id')->value('users.id');

        return $id === null ? null : (int) $id;
    }

    /** Eligible owners for an assignment picker (bounded). @return list<array{id: int, name: string, store_ids: list<int>}> */
    public function candidates(Organization $organization, int $limit = 100): array
    {
        return $this->eligibleQuery($organization, null)
            ->with(['storeMemberships' => fn ($query) => $query->where('organization_id', $organization->getKey())->select(['id', 'user_id', 'store_id'])])
            ->orderBy('name')->limit($limit)->get(['users.id', 'users.name'])
            ->map(fn (User $user) => ['id' => (int) $user->getKey(), 'name' => (string) $user->name,
                'store_ids' => $user->storeMemberships->pluck('store_id')->map(fn ($id) => (int) $id)->values()->all()])
            ->all();
    }

    /** Background ownership repair for one organization's active items. Returns the number of items changed. */
    public function repairOrganization(Organization $organization): int
    {
        $changed = 0;
        AttentionItem::query()->where('organization_id', $organization->getKey())->whereNotNull('active_key')
            ->select(['id', 'store_id', 'quotation_id', 'assigned_user_id', 'assignment_source', 'ownership_resolved_at', 'severity', 'version'])
            ->chunkById(self::CHUNK, function ($items) use ($organization, &$changed) {
                $quotations = Quotation::query()->where('organization_id', $organization->getKey())
                    ->whereKey($items->pluck('quotation_id')->filter()->unique()->all())
                    ->get(['id', 'issued_by_user_id', 'created_by_user_id'])->keyBy('id');
                // Batched eligibility: one query per store present in the chunk.
                $eligible = [];
                foreach ($items->groupBy('store_id') as $storeId => $group) {
                    $ids = $group->pluck('assigned_user_id')
                        ->merge($group->map(fn ($item) => $quotations->get($item->quotation_id)?->issued_by_user_id))
                        ->merge($group->map(fn ($item) => $quotations->get($item->quotation_id)?->created_by_user_id))
                        ->filter()->all();
                    $eligible[(int) $storeId] = array_flip($this->eligibleUserIds($organization, $storeId === '' ? null : (int) $storeId, $ids));
                }
                $fallbacks = [];

                foreach ($items as $item) {
                    $valid = $eligible[(int) $item->store_id] ?? [];
                    $owner = $item->assigned_user_id === null ? null : (int) $item->assigned_user_id;
                    $source = $item->assignment_source;
                    $resolved = $item->ownership_resolved_at !== null;

                    if (! $resolved) {
                        $quotation = $quotations->get($item->quotation_id);
                        if ($owner === null || ! isset($valid[$owner])) {
                            [$owner, $source] = match (true) {
                                $quotation?->issued_by_user_id !== null && isset($valid[(int) $quotation->issued_by_user_id]) => [(int) $quotation->issued_by_user_id, self::SOURCE_ISSUER],
                                $quotation?->created_by_user_id !== null && isset($valid[(int) $quotation->created_by_user_id]) => [(int) $quotation->created_by_user_id, self::SOURCE_CREATOR],
                                default => [null, null],
                            };
                        }
                    } elseif ($owner !== null && ! isset($valid[$owner])) {
                        [$owner, $source] = [null, null]; // Owner lost access.
                    }
                    if ($owner === null && $source !== self::SOURCE_MANUAL && $item->severity === AttentionSeverity::Critical) {
                        $fallback = $fallbacks[(int) $item->store_id] ??= ($this->fallbackManager($organization, $item->store_id) ?? 0);
                        if ($fallback > 0) {
                            [$owner, $source] = [$fallback, self::SOURCE_FALLBACK];
                        }
                    }

                    $unchanged = $resolved && $owner === ($item->assigned_user_id === null ? null : (int) $item->assigned_user_id) && $source === $item->assignment_source;
                    if (! $unchanged && $this->apply((int) $item->getKey(), (int) $item->version, $owner, $source)) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /** Apply a background decision only if nobody changed the row since it was read. */
    private function apply(int $id, int $version, ?int $owner, ?string $source): bool
    {
        return DB::transaction(function () use ($id, $version, $owner, $source) {
            $item = AttentionItem::query()->whereKey($id)->lockForUpdate()->first();
            if (! $item || $item->active_key === null || (int) $item->version !== $version) {
                return false;
            }
            $item->assigned_user_id = $owner;
            $item->assignment_source = $source;
            $item->ownership_resolved_at ??= now();
            $item->version = $version + 1;
            $item->save();

            return true;
        });
    }

    private function eligibleQuery(Organization $organization, ?int $storeId): Builder
    {
        $organizationId = $organization->getKey();

        return User::query()
            ->whereHas('organizationMemberships', fn (Builder $membership) => $membership
                ->where('organization_id', $organizationId)->where('status', 'active')
                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('key', 'quotations.view')))
            ->when($storeId !== null, fn (Builder $query) => $query->whereHas('storeMemberships', fn (Builder $memberships) => $memberships
                ->where('organization_id', $organizationId)->where('store_id', $storeId)));
    }
}
