<?php

namespace App\Actions\OrganizationBackups;

use App\Jobs\CreateScheduledOrganizationBackupJob;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupSetting;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class DispatchDueOrganizationBackupsAction
{
    public function __construct(
        private readonly OrganizationBackupSchedule $schedule,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(?CarbonImmutable $now = null): int
    {
        $dispatched = 0;

        OrganizationBackupSetting::query()
            ->with('organization')
            ->where('enabled', true)
            ->chunkById(100, function ($settings) use (&$dispatched, $now): void {
                foreach ($settings as $setting) {
                    $slot = $this->schedule->dueSlot($setting, $now);
                    if (! $slot || ! $setting->organization) {
                        continue;
                    }

                    try {
                        $backup = new OrganizationBackup;
                        $backup->organization_id = $setting->organization_id;
                        $backup->uuid = (string) Str::uuid();
                        $backup->type = OrganizationBackup::TYPE_SCHEDULED;
                        $backup->status = OrganizationBackup::STATUS_QUEUED;
                        $backup->scheduled_for = $slot['scheduled_for'];
                        $backup->scheduled_slot = $slot['slot'];
                        $backup->save();
                    } catch (QueryException) {
                        continue;
                    }

                    $this->audit->record('organization_backup.scheduled', null, $setting->organization, newValues: [
                        'backup_uuid' => $backup->uuid,
                        'scheduled_for' => $backup->scheduled_for?->toIso8601String(),
                    ]);

                    CreateScheduledOrganizationBackupJob::dispatch($backup->getKey());
                    $dispatched++;
                }
            });

        return $dispatched;
    }
}
