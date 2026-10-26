<?php

namespace App\Services\FollowUps;

use App\Models\FollowUp;
use App\Models\FollowUpRecurringRule;
use App\Models\Organization;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of follow-up authorization, independent of transport:
 * the web policy and the canonical actions (and later MCP/API) all call it
 * with an explicit organization resolved server-side.
 *
 * - follow_ups.view: own work (assigned to the user);
 * - follow_ups.update: change own work; follow_ups.create: create own work;
 * - follow_ups.manage_all: see/change everyone's work (including Unassigned
 *   work, assigned_user_id NULL) and assign to others;
 * - a store-linked follow-up additionally requires store membership.
 *
 * W3 recurring rules follow exactly the same rules (same columns): own rule
 * = assigned to the user; anything else (other users, Unassigned) needs
 * manage_all.
 */
class FollowUpAccess
{
    /** Per-instance (per-request / per-job) memo; never shared across requests. @var array<string, list<string>> */
    private array $permissionKeys = [];

    /** @var array<int, list<int>> */
    private array $memberStoreIds = [];

    /** @var array<string, list<int>> */
    private array $organizationStoreIds = [];

    public function allows(User $user, Organization|int|string $organization, string $permission): bool
    {
        $organizationId = (int) ($organization instanceof Organization ? $organization->getKey() : $organization);
        $key = $user->getKey().':'.$organizationId;

        return in_array($permission, $this->permissionKeys[$key] ??= $user->permissionKeysFor($organizationId), true);
    }

    public function canViewAny(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'follow_ups.view');
    }

    public function canCreate(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'follow_ups.create');
    }

    public function canManageAll(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, 'follow_ups.manage_all');
    }

    public function canView(User $user, FollowUp|FollowUpRecurringRule $followUp): bool
    {
        $organizationId = (int) $followUp->organization_id;
        $own = (int) $followUp->assigned_user_id === (int) $user->getKey();

        return (($own && $this->allows($user, $organizationId, 'follow_ups.view')) || $this->allows($user, $organizationId, 'follow_ups.manage_all'))
            && $this->hasStoreAccess($user, $followUp->store_id);
    }

    public function canManage(User $user, FollowUp|FollowUpRecurringRule $followUp): bool
    {
        $organizationId = (int) $followUp->organization_id;
        $own = (int) $followUp->assigned_user_id === (int) $user->getKey();

        return $this->allows($user, $organizationId, 'follow_ups.update')
            && ($own || $this->allows($user, $organizationId, 'follow_ups.manage_all'))
            && $this->hasStoreAccess($user, $followUp->store_id);
    }

    public function hasStoreAccess(User $user, int|string|null $storeId): bool
    {
        return $storeId === null
            || in_array((int) $storeId, $this->memberStoreIds[(int) $user->getKey()] ??= StoreMembership::query()
                ->where('user_id', $user->getKey())->pluck('store_id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /** @return list<int> */
    public function storeIds(User $user, Organization $organization): array
    {
        return $this->organizationStoreIds[$user->getKey().':'.$organization->getKey()] ??= StoreMembership::query()
            ->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())
            ->pluck('store_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Follow-ups the user may see in the organization (tenant + assignment + store scope). */
    public function visible(User $user, Organization $organization): Builder
    {
        $storeIds = $this->storeIds($user, $organization);

        return FollowUp::query()
            ->where('organization_id', $organization->getKey())
            ->when(! $this->canManageAll($user, $organization), fn (Builder $query) => $query->where('assigned_user_id', $user->getKey()))
            ->where(fn (Builder $query) => $query->whereNull('store_id')->orWhereIn('store_id', $storeIds));
    }

    /** Recurring rules the user may see (same scope as visible()). */
    public function visibleRules(User $user, Organization $organization): Builder
    {
        $storeIds = $this->storeIds($user, $organization);

        return FollowUpRecurringRule::query()
            ->where('organization_id', $organization->getKey())
            ->when(! $this->canManageAll($user, $organization), fn (Builder $query) => $query->where('assigned_user_id', $user->getKey()))
            ->where(fn (Builder $query) => $query->whereNull('store_id')->orWhereIn('store_id', $storeIds));
    }

    public function authorize(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
