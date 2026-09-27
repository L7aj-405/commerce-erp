<?php

namespace App\Services\OrganizationBackups;

use App\Models\OrganizationBackupSetting;
use Carbon\CarbonImmutable;

class OrganizationBackupSchedule
{
    /** @return array{slot:string, scheduled_for:\Carbon\CarbonImmutable}|null */
    public function dueSlot(OrganizationBackupSetting $setting, ?CarbonImmutable $now = null): ?array
    {
        if (! $setting->enabled) {
            return null;
        }

        $timezone = $this->timezone($setting);
        $localNow = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($timezone);
        $time = substr((string) $setting->time_of_day, 0, 5) ?: '03:00';
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $candidate = $localNow->setTime($hour, $minute, 0);

        if ($setting->frequency === 'weekly') {
            $dayOfWeek = $setting->day_of_week;
            if ($dayOfWeek === null || (int) $dayOfWeek !== $localNow->dayOfWeek) {
                return null;
            }
        }

        if ($localNow->lessThan($candidate)) {
            return null;
        }

        return [
            'slot' => $candidate->format('Y-m-d H:i').' '.$timezone,
            'scheduled_for' => $candidate->setTimezone('UTC'),
        ];
    }

    public function nextRun(OrganizationBackupSetting $setting, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if (! $setting->enabled) {
            return null;
        }

        $timezone = $this->timezone($setting);
        $localNow = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($timezone);
        $time = substr((string) $setting->time_of_day, 0, 5) ?: '03:00';
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $candidate = $localNow->setTime($hour, $minute, 0);

        if ($setting->frequency === 'weekly') {
            $target = (int) ($setting->day_of_week ?? 1);
            while ($candidate->dayOfWeek !== $target || $candidate->lessThanOrEqualTo($localNow)) {
                $candidate = $candidate->addDay()->setTime($hour, $minute, 0);
            }

            return $candidate->setTimezone('UTC');
        }

        if ($candidate->lessThanOrEqualTo($localNow)) {
            $candidate = $candidate->addDay();
        }

        return $candidate->setTimezone('UTC');
    }

    private function timezone(OrganizationBackupSetting $setting): string
    {
        $timezone = $setting->timezone ?: config('app.timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
