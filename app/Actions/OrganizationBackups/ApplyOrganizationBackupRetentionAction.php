<?php

namespace App\Actions\OrganizationBackups;

use App\Models\Organization;
use App\Models\OrganizationBackup;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupStorage;

class ApplyOrganizationBackupRetentionAction
{
    public function __construct(
        private readonly OrganizationBackupStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Organization $organization, int $keep): void
    {
        $keep = max((int) config('organization-backups.retention_min', 7), min($keep, (int) config('organization-backups.retention_max', 90)));

        $backups = OrganizationBackup::query()
            ->where('organization_id', $organization->getKey())
            ->where('type', OrganizationBackup::TYPE_SCHEDULED)
            ->where('status', OrganizationBackup::STATUS_COMPLETED)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        foreach ($backups->skip($keep) as $backup) {
            try {
                $this->storage->delete($backup);
                $backup->status = OrganizationBackup::STATUS_DELETED_BY_RETENTION;
                $backup->storage_path = null;
                $backup->failure_message = null;
                $backup->save();

                $this->audit->record('organization_backup.deleted_by_retention', null, $organization, newValues: [
                    'backup_uuid' => $backup->uuid,
                    'completed_at' => $backup->completed_at?->toIso8601String(),
                ]);
            } catch (\Throwable $exception) {
                $backup->failure_message = 'retention_delete_failed';
                $backup->save();
            }
        }
    }
}
