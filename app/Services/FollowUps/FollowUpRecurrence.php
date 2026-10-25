<?php

namespace App\Services\FollowUps;

use App\Enums\FollowUpRecurrenceFrequency;
use App\Models\FollowUpRecurringRule;
use Carbon\CarbonImmutable;

/**
 * W3 recurrence calendar — pure organization-local date arithmetic on
 * `Y-m-d` strings (no timezone, no clock time; BusinessTime converts later).
 *
 * Supported: every N days; every N weeks on one or more ISO weekdays (1 = Mon
 * … 7 = Sun), weeks counted from the Monday of `starts_on`; every N months on
 * a day of month, months counted from the month of `starts_on`. A month that
 * lacks `month_day` (31 → April, 29–31 → February) uses its last day — never
 * skipped. Dates before `starts_on` or after `ends_on` are never eligible.
 * No yearly/cron/RRULE/nth-weekday/business-day rules.
 */
final class FollowUpRecurrence
{
    /** First eligible occurrence date on or after `$date`, or NULL when the rule has no more dates. */
    public function firstOnOrAfter(FollowUpRecurringRule $rule, string $date): ?string
    {
        $start = CarbonImmutable::parse($rule->starts_on, 'UTC');
        $from = CarbonImmutable::parse($date, 'UTC');
        if ($from->lessThan($start)) {
            $from = $start;
        }
        $interval = max(1, (int) $rule->interval_count);

        $candidate = match ($rule->frequency) {
            FollowUpRecurrenceFrequency::Daily => $this->daily($start, $from, $interval),
            FollowUpRecurrenceFrequency::Weekly => $this->weekly($start, $from, $interval, $this->weekdays($rule, $start)),
            FollowUpRecurrenceFrequency::Monthly => $this->monthly($start, $from, $interval, (int) ($rule->month_day ?: $start->day)),
        };

        if ($candidate === null || ($rule->ends_on !== null && $candidate->toDateString() > $rule->ends_on)) {
            return null;
        }

        return $candidate->toDateString();
    }

    /** The eligible occurrence date strictly after `$date`. */
    public function after(FollowUpRecurringRule $rule, string $date): ?string
    {
        return $this->firstOnOrAfter($rule, CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString());
    }

    private function daily(CarbonImmutable $start, CarbonImmutable $from, int $interval): CarbonImmutable
    {
        $steps = (int) ceil($start->diffInDays($from) / $interval);

        return $start->addDays($steps * $interval);
    }

    /** @param list<int> $weekdays */
    private function weekly(CarbonImmutable $start, CarbonImmutable $from, int $interval, array $weekdays): ?CarbonImmutable
    {
        $anchor = $start->startOfWeek(CarbonImmutable::MONDAY);
        // Bounded: one full cycle of `interval` weeks (+1) always contains a match.
        for ($day = $from, $i = 0; $i < 7 * ($interval + 1); $i++, $day = $day->addDay()) {
            $weeks = intdiv((int) $anchor->diffInDays($day->startOfWeek(CarbonImmutable::MONDAY)), 7);
            if ($weeks % $interval === 0 && in_array($day->dayOfWeekIso, $weekdays, true)) {
                return $day;
            }
        }

        return null;
    }

    private function monthly(CarbonImmutable $start, CarbonImmutable $from, int $interval, int $monthDay): ?CarbonImmutable
    {
        $anchor = $start->startOfMonth();
        $months = ($from->year - $anchor->year) * 12 + ($from->month - $anchor->month);
        $months = (int) (ceil($months / $interval) * $interval);
        // Bounded: at most two cycles (the aligned month may fall before `$from`).
        for ($i = 0; $i < 3; $i++, $months += $interval) {
            $month = $anchor->addMonthsNoOverflow($months);
            $candidate = $month->setDay(min($monthDay, $month->daysInMonth));
            if ($candidate->greaterThanOrEqualTo($from)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function weekdays(FollowUpRecurringRule $rule, CarbonImmutable $start): array
    {
        $days = array_values(array_unique(array_map('intval', (array) ($rule->weekdays ?? []))));

        return $days !== [] ? $days : [$start->dayOfWeekIso];
    }
}
