<?php

namespace App\Services\FollowUps;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may be assigned follow-up work: an active member of the organization
 * whose role grants follow_ups.view and, for store-linked work, a member of
 * that store. Internal canonical lookup (the future list_assignable_users
 * MCP tool must call this, never query users directly).
 */
class FollowUpAssignees
{
    /** @return Collection<int, array{id: int, name: string}> */
    public function assignable(Organization $organization, ?int $storeId = null, ?string $search = null, int $limit = 50): Collection
    {
        $search = trim((string) $search);

        return $this->query($organization, $storeId)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->limit(max(1, min($limit, 100)))
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => (int) $user->getKey(), 'name' => (string) $user->name]);
    }

    /**
     * `$lock` (inside a transaction) re-reads the member's active organization
     * and store membership rows with a shared lock: a concurrent removal or
     * suspension either commits first (and the user is rejected) or waits for
     * this assignment, whose open work FollowUpAssignmentReleaser then frees.
     */
    public function isAssignable(Organization $organization, int $userId, ?int $storeId = null, bool $lock = false): bool
    {
        if ($lock) {
            $organizationId = $organization->getKey();
            $member = OrganizationMembership::query()->where('organization_id', $organizationId)->where('user_id', $userId)
                ->where('status', 'active')->sharedLock()->exists();
            $storeMember = $storeId === null || StoreMembership::query()->where('organization_id', $organizationId)
                ->where('store_id', $storeId)->where('user_id', $userId)->sharedLock()->exists();
            if (! $member || ! $storeMember) {
                return false;
            }
        }

        return $this->query($organization, $storeId)->whereKey($userId)->exists();
    }

    private function query(Organization $organization, ?int $storeId): Builder
    {
        $organizationId = $organization->getKey();

        return User::query()
            ->whereHas('organizationMemberships', fn (Builder $query) => $query
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('key', 'follow_ups.view')))
            ->when($storeId !== null, fn (Builder $query) => $query->whereHas('storeMemberships', fn (Builder $memberships) => $memberships
                ->where('organization_id', $organizationId)->where('store_id', $storeId)));
    }
}
