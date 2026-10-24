<?php

namespace App\Services\OrganizationBackups;

use App\Models\Organization;
use App\Models\OrganizationBackupSetting;

class OrganizationBackupSettingsService
{
    public function forOrganization(Organization $organization): OrganizationBackupSetting
    {
        $setting = $organization->backupSetting()->first();
        if ($setting) {
            return $setting;
        }

        $setting = new OrganizationBackupSetting;
        $setting->organization_id = $organization->getKey();
        $setting->enabled = false;
        $setting->frequency = 'daily';
        $setting->time_of_day = '03:00:00';
        $setting->day_of_week = 1;
        $setting->timezone = $this->organizationTimezone($organization);
        $setting->retention_count = 30;
        $setting->notify_on_failure = false;
        $setting->save();

        return $setting;
    }

    public function organizationTimezone(Organization $organization): string
    {
        return $organization->businessTimezone();
    }
}
