<?php

namespace App\Services\FollowUps;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Enums\FollowUpRecurringStatus;
use App\Enums\QuotationStatus;
use App\Models\FollowUp;
use App\Models\FollowUpRecurringRule;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * W3: turns recurring rules into concrete follow-up occurrences.
 *
 * One occurrence ahead: occurrence D is generated as soon as the previous
 * occurrence's local day starts (`next_generate_at` = 00:00 local of the last
 * generated date, or "now" for a new/resumed rule). So a weekly Monday rule
 * created on a Monday holds today's and next Monday's items; created on a
 * Tuesday it holds next Monday's only. Nothing is pre-generated further.
 *
 * No backlog: if the cursor date is already in the past (scheduler down,
 * organization suspended), it jumps to the first eligible date on or after
 * the organization's today — at most one catch-up occurrence, never one per
 * missed date. Pausing generates nothing; resuming starts from today.
 *
 * Exactly once: callers hold the rule row lock, and UNIQUE (organization_id,
 * recurring_rule_id, recurrence_key) on follow_ups makes a duplicate insert
 * impossible even if two generators raced. Occurrences are written by
 * SaveFollowUpAction::createOccurrence (W2 reminder armed there).
 *
 * Generation stops safely instead of producing invisible or meaningless work:
 * - assignee no longer assignable → rule Unassigned + paused (assignee_lost);
 * - quotation-linked rule whose quotation is gone or no longer current
 *   (QuotationStatus::isCurrentEligible, or still a Draft) → paused
 *   (quotation_not_current);
 * - `ends_on` passed or `max_occurrences` reached → ended (schedule_complete).
 */
final class FollowUpRecurringGenerator
{
    private const MAX_PER_CALL = 3;

    public function __construct(
        private readonly FollowUpRecurrence $recurrence,
        private readonly SaveFollowUpAction $followUps,
        private readonly FollowUpAssignees $assignees,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Recompute the cursor and generation gate from the organization's today
     * (never before `starts_on` or the last generated date). The caller holds
     * the rule lock and saves.
     */
    public function reschedule(Organization $organization, FollowUpRecurringRule $rule): void
    {
        if ($rule->status !== FollowUpRecurringStatus::Active) {
            $rule->next_occurrence_on = null;
            $rule->next_generate_at = null;

            return;
        }

        $rule->next_occurrence_on = $this->recurrence->firstOnOrAfter($rule, $this->cursorStart($organization, $rule));
        // An exhausted schedule is ended by the next generate() call (audited there).
        $rule->next_generate_at = $rule->next_occurrence_on === null ? CarbonImmutable::now('UTC') : $this->gate($organization, $rule);
    }

    /** Generate what is due for a locked rule; returns the number of occurrences created. */
    public function generate(Organization $organization, FollowUpRecurringRule $rule, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $created = 0;

        for ($i = 0; $i < self::MAX_PER_CALL; $i++) {
            if ($rule->status !== FollowUpRecurringStatus::Active || $rule->next_generate_at === null || $rule->next_generate_at->greaterThan($now)) {
                break;
            }

            $date = $rule->next_occurrence_on;
            if ($date === null || $date < BusinessTime::today($organization)) {
                $date = $this->recurrence->firstOnOrAfter($rule, $this->cursorStart($organization, $rule));
            }
            if ($date === null || ($rule->max_occurrences !== null && $rule->occurrences_generated >= $rule->max_occurrences)) {
                $this->stop($organization, $rule, FollowUpRecurringStatus::Ended, 'schedule_complete');
                break;
            }
            if ($reason = $this->blocker($organization, $rule)) {
                $this->stop($organization, $rule, FollowUpRecurringStatus::Paused, $reason);
                break;
            }

            if ($this->createOnce($organization, $rule, $date)) {
                $created++;
                $rule->occurrences_generated = (int) $rule->occurrences_generated + 1;
            }
            $rule->last_generated_on = $date;
            $rule->next_occurrence_on = $this->recurrence->after($rule, $date);
            $rule->next_generate_at = $this->gate($organization, $rule);

            if ($rule->next_occurrence_on === null || ($rule->max_occurrences !== null && $rule->occurrences_generated >= $rule->max_occurrences)) {
                $this->stop($organization, $rule, FollowUpRecurringStatus::Ended, 'schedule_complete');
                break;
            }
        }

        if ($rule->isDirty()) {
            $rule->save();
        }

        return $created;
    }

    /** Scheduler entry point: bounded chunks, one row-locked transaction per rule, failures isolated. @return array{created: int, rules: int, failed: int} */
    public function generateDue(?CarbonImmutable $now = null, int $limit = 1000): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $result = ['created' => 0, 'rules' => 0, 'failed' => 0];
        $processed = 0;

        FollowUpRecurringRule::query()
            ->where('status', FollowUpRecurringStatus::Active->value)
            ->whereNotNull('next_generate_at')->where('next_generate_at', '<=', $now)
            ->select(['id', 'organization_id'])
            ->chunkById(100, function ($candidates) use ($now, $limit, &$result, &$processed) {
                $organizations = Organization::query()->whereKey($candidates->pluck('organization_id')->unique()->all())->get()->keyBy('id');
                foreach ($candidates as $candidate) {
                    if ($processed++ >= $limit) {
                        return false;
                    }
                    try {
                        $result['created'] += DB::transaction(function () use ($candidate, $organizations, $now) {
                            $rule = FollowUpRecurringRule::query()->whereKey($candidate->getKey())->lockForUpdate()->first();
                            $organization = $organizations->get($candidate->organization_id);
                            if (! $rule || ! $organization) {
                                return 0;
                            }
                            if ($organization->status !== 'active') {
                                // Suspended organization: retry later, generate nothing (no backlog on return).
                                $rule->next_generate_at = $now->addHour();
                                $rule->save();

                                return 0;
                            }

                            return $this->generate($organization, $rule, $now);
                        });
                        $result['rules']++;
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }
            });

        return $result;
    }

    /** After an organization timezone change: only the UTC generation gate moves; local dates and generated occurrences do not. */
    public function rescheduleOrganization(Organization $organization): int
    {
        $count = 0;
        FollowUpRecurringRule::query()->where('organization_id', $organization->getKey())
            ->where('status', FollowUpRecurringStatus::Active->value)
            ->select(['id'])->chunkById(200, function ($rules) use ($organization, &$count) {
                foreach ($rules as $row) {
                    DB::transaction(function () use ($organization, $row, &$count) {
                        $rule = FollowUpRecurringRule::query()->whereKey($row->getKey())->lockForUpdate()->first();
                        if ($rule && $rule->status === FollowUpRecurringStatus::Active) {
                            $rule->next_generate_at = $rule->next_occurrence_on === null ? CarbonImmutable::now('UTC') : $this->gate($organization, $rule);
                            $rule->save();
                            $count++;
                        }
                    });
                }
            });

        return $count;
    }

    /** Why generation must stop for this rule right now (NULL = may generate). */
    public function blocker(Organization $organization, FollowUpRecurringRule $rule): ?string
    {
        // The store is only ever derived from a quotation: a store-linked rule is quotation-linked.
        if ($rule->store_id !== null) {
            $quotation = $rule->quotation_id === null ? null
                : Quotation::query()->where('organization_id', $organization->getKey())->whereKey($rule->quotation_id)->first(['id', 'status']);
            if (! $quotation || ! self::quotationIsActionable($quotation->status)) {
                return 'quotation_not_current';
            }
        }
        if ($rule->assigned_user_id !== null
            && ! $this->assignees->isAssignable($organization, (int) $rule->assigned_user_id, $rule->store_id, lock: true)) {
            return 'assignee_lost';
        }

        return null;
    }

    /** A quotation still worth following up: being prepared (Draft) or the current version of the proposal. */
    public static function quotationIsActionable(QuotationStatus $status): bool
    {
        return $status === QuotationStatus::Draft || $status->isCurrentEligible();
    }

    /** Pause or end a locked rule on behalf of the system (or `$actor`), audited. */
    public function stop(Organization $organization, FollowUpRecurringRule $rule, FollowUpRecurringStatus $status, string $reason, ?User $actor = null): void
    {
        $old = ['status' => $rule->status->value, 'assigned_user_id' => $rule->assigned_user_id === null ? null : (int) $rule->assigned_user_id];
        if ($reason === 'assignee_lost') {
            // W1.1 rule: work never stays with someone who lost access.
            $rule->assigned_user_id = null;
        }
        $rule->status = $status;
        $rule->status_reason = $reason;
        $rule->next_occurrence_on = null;
        $rule->next_generate_at = null;
        $rule->version = (int) $rule->version + 1;
        $rule->save();

        $this->audit->record($status === FollowUpRecurringStatus::Ended ? 'follow_up_recurring.ended' : 'follow_up_recurring.paused',
            $actor, $organization, null, $rule, oldValues: $old,
            newValues: ['recurring_rule_id' => (int) $rule->getKey(), 'status' => $status->value, 'reason' => $reason,
                'assigned_user_id' => $rule->assigned_user_id === null ? null : (int) $rule->assigned_user_id]);
    }

    private function createOnce(Organization $organization, FollowUpRecurringRule $rule, string $date): bool
    {
        $exists = FollowUp::query()->where('organization_id', $organization->getKey())
            ->where('recurring_rule_id', $rule->getKey())->where('recurrence_key', $date)->exists();
        if ($exists) {
            return false;
        }

        try {
            DB::transaction(fn () => $this->followUps->createOccurrence($organization, $rule, $date, $this->dueAt($organization, $rule, $date)));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false; // Another generator created this exact occurrence.
        }
    }

    /** Timed occurrence instant: local_time on that date in the organization timezone; a time skipped by DST moves one hour later. */
    private function dueAt(Organization $organization, FollowUpRecurringRule $rule, string $date): ?CarbonImmutable
    {
        if ($rule->local_time === null) {
            return null;
        }
        try {
            return BusinessTime::localToUtc($organization, $date, $rule->local_time);
        } catch (InvalidArgumentException) {
            return BusinessTime::localToUtc($organization, $date, CarbonImmutable::createFromFormat('H:i', $rule->local_time, 'UTC')->addHour()->format('H:i'));
        }
    }

    private function cursorStart(Organization $organization, FollowUpRecurringRule $rule): string
    {
        $candidates = [BusinessTime::today($organization), (string) $rule->starts_on];
        if ($rule->last_generated_on !== null) {
            $candidates[] = CarbonImmutable::parse($rule->last_generated_on, 'UTC')->addDay()->toDateString();
        }

        return max($candidates);
    }

    private function gate(Organization $organization, FollowUpRecurringRule $rule): CarbonImmutable
    {
        if ($rule->last_generated_on === null) {
            return CarbonImmutable::now('UTC');
        }
        try {
            return BusinessTime::localToUtc($organization, (string) $rule->last_generated_on);
        } catch (InvalidArgumentException) {
            // Local midnight skipped by DST in a few zones: 01:00 exists.
            return BusinessTime::localToUtc($organization, (string) $rule->last_generated_on, '01:00');
        }
    }
}
