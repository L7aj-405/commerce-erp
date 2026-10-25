<?php

namespace App\Services\FollowUps;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * W2 due-reminder occurrence rules (the single source, used by
 * SaveFollowUpAction, TransitionFollowUpAction and timezone changes):
 *
 * - timed follow-up: the reminder instant is `due_at` (UTC);
 * - date-only follow-up: DATE_ONLY_LOCAL_TIME (09:00) on `due_date` in the
 *   organization business timezone (UTC fallback when none is configured),
 *   converted to UTC — never 00:00;
 * - undated follow-up: no reminder.
 *
 * `remind_at` holds the pending occurrence (NULL = nothing pending). It is
 * armed only when the instant still lies in the future at the moment the due
 * value is set (create, reschedule, reopen), so editing a due date into the
 * past, reopening overdue work or saving an already-passed reminder never
 * fires an immediate catch-up reminder (no loops on repeated reopen).
 * Completing/cancelling clears it; notifying consumes it (NotifyDueFollowUpsAction).
 */
final class FollowUpReminderSchedule
{
    public const DATE_ONLY_LOCAL_TIME = '09:00';

    public function instant(Organization $organization, FollowUp $followUp): ?CarbonImmutable
    {
        if ($followUp->due_at !== null) {
            return CarbonImmutable::instance($followUp->due_at)->utc();
        }
        if ($followUp->due_date === null) {
            return null;
        }

        try {
            return BusinessTime::localToUtc($organization, (string) $followUp->due_date, self::DATE_ONLY_LOCAL_TIME);
        } catch (InvalidArgumentException) {
            // 09:00 skipped by a DST change: the first instant after it.
            return BusinessTime::localToUtc($organization, (string) $followUp->due_date)->addHours(9);
        }
    }

    /** The occurrence to arm now: the reminder instant if it is still in the future and the item is open, else NULL. */
    public function upcoming(Organization $organization, FollowUp $followUp): ?CarbonImmutable
    {
        $instant = $followUp->status === FollowUpStatus::Open ? $this->instant($organization, $followUp) : null;

        return $instant !== null && $instant->isFuture() ? $instant : null;
    }

    /**
     * After an organization timezone change, move every still-pending
     * date-only reminder to 09:00 in the new timezone. Pending occurrences
     * stay pending (even if the new instant has passed: it was never
     * delivered). Bounded chunks; timed reminders are absolute and untouched.
     */
    public function realignDateOnly(Organization $organization): int
    {
        $realigned = 0;
        FollowUp::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', FollowUpStatus::Open->value)
            ->whereNotNull('remind_at')->whereNull('due_at')->whereNotNull('due_date')
            ->select(['id', 'organization_id', 'status', 'due_date', 'due_at', 'remind_at'])
            ->chunkById(500, function ($followUps) use ($organization, &$realigned) {
                foreach ($followUps as $followUp) {
                    $instant = $this->instant($organization, $followUp);
                    $realigned += FollowUp::query()->whereKey($followUp->getKey())->whereNotNull('remind_at')
                        ->update(['remind_at' => $instant]);
                }
            });

        return $realigned;
    }
}
