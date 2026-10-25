<?php

namespace App\Services\FollowUps;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The single place where open follow-ups lose their assignee (W1.1).
 *
 * Called by every path that can take follow-up access away from a member —
 * membership removal/suspension, role change, role permission edits, store
 * access removal and backup restore (the Users & Access data reset applies
 * the same rule as one bulk update in OrganizationDataResetOperationQuery,
 * since audit rows are frozen during a reset and the reset's own audit
 * carries the count) — so open work lands in the manager-visible
 * "Unassigned" bucket instead of staying hidden under a departed user. Completed/cancelled history, creator,
 * completer and every tenant/store/customer/quotation link are untouched;
 * nothing is deleted.
 *
 * Retry-safe: candidates are re-selected under a row lock with the same
 * predicate, so a follow-up reassigned concurrently to a valid member is left
 * alone and a second run releases nothing. Each release bumps `version` (stale
 * tabs are rejected) and is audited as follow_up.reassigned → NULL.
 */
class FollowUpAssignmentReleaser
{
    private const CHUNK = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Release open work whose assignee can no longer hold it (optionally for one user only). */
    public function releaseDeparted(Organization $organization, ?User $actor, string $reason, ?int $userId = null): int
    {
        return $this->release($organization, fn () => $this->staleAssignments($organization, $userId), $actor, $reason);
    }

    /**
     * Open, assigned follow-ups whose assignee has no active membership with
     * follow_ups.view, or no membership of the follow-up's store.
     */
    public function staleAssignments(Organization $organization, ?int $userId = null): Builder
    {
        $organizationId = (int) $organization->getKey();

        return $this->openAssigned($organization)
            ->when($userId !== null, fn (Builder $query) => $query->where('assigned_user_id', $userId))
            ->where(fn (Builder $query) => $query
                ->whereNotExists(fn (QueryBuilder $membership) => $membership->selectRaw('1')
                    ->from('organization_memberships as membership')
                    ->join('role_permission as role_permission', 'role_permission.role_id', '=', 'membership.role_id')
                    ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
                    ->where('membership.organization_id', $organizationId)
                    ->whereColumn('membership.user_id', 'follow_ups.assigned_user_id')
                    ->where('membership.status', 'active')
                    ->where('permission.key', 'follow_ups.view'))
                ->orWhere(fn (Builder $query) => $query->whereNotNull('follow_ups.store_id')
                    ->whereNotExists(fn (QueryBuilder $storeMembership) => $storeMembership->selectRaw('1')
                        ->from('store_memberships as store_membership')
                        ->where('store_membership.organization_id', $organizationId)
                        ->whereColumn('store_membership.store_id', 'follow_ups.store_id')
                        ->whereColumn('store_membership.user_id', 'follow_ups.assigned_user_id'))));
    }

    /** @param callable(): Builder $candidates re-evaluated under lock for every chunk */
    public function release(Organization $organization, callable $candidates, ?User $actor, string $reason): int
    {
        return DB::transaction(function () use ($organization, $candidates, $actor, $reason) {
            $released = 0;
            foreach ($candidates()->orderBy('follow_ups.id')->pluck('follow_ups.id')->chunk(self::CHUNK) as $ids) {
                $locked = $candidates()->whereIn('follow_ups.id', $ids->all())->orderBy('follow_ups.id')->lockForUpdate()->get();
                $stores = Store::query()->whereKey($locked->pluck('store_id')->filter()->unique()->all())->get()->keyBy('id');

                foreach ($locked as $followUp) {
                    $previous = (int) $followUp->assigned_user_id;
                    $followUp->assigned_user_id = null;
                    $followUp->version = (int) $followUp->version + 1;
                    $followUp->save();

                    $this->audit->record('follow_up.reassigned', $actor, $organization, $followUp->store_id ? $stores->get($followUp->store_id) : null, $followUp,
                        oldValues: ['assigned_user_id' => $previous],
                        newValues: ['follow_up_id' => $followUp->getKey(), 'assigned_user_id' => null, 'customer_id' => $followUp->customer_id,
                            'quotation_id' => $followUp->quotation_id, 'reason' => $reason]);
                    $released++;
                }
            }

            return $released;
        });
    }

    private function openAssigned(Organization $organization): Builder
    {
        return FollowUp::query()
            ->where('follow_ups.organization_id', $organization->getKey())
            ->where('follow_ups.status', FollowUpStatus::Open->value)
            ->whereNotNull('follow_ups.assigned_user_id');
    }
}
