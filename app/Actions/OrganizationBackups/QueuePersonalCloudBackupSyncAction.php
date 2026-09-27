<?php

namespace App\Actions\OrganizationBackups;

use App\Jobs\SyncOrganizationBackupToPersonalCloudJob;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupCloudCopy;
use App\Models\OrganizationCloudBackupConnection;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;

class QueuePersonalCloudBackupSyncAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(OrganizationBackup $backup): ?OrganizationBackupCloudCopy
    {
        $organization = $backup->organization;
        if (! $organization || $backup->status !== OrganizationBackup::STATUS_COMPLETED) {
            return null;
        }

        $connection = OrganizationCloudBackupConnection::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)
            ->where('is_enabled', true)
            ->first();

        if (! $connection) {
            return null;
        }

        try {
            $copy = new OrganizationBackupCloudCopy;
            $copy->organization_backup_id = $backup->getKey();
            $copy->organization_id = $organization->getKey();
            $copy->connection_id = $connection->getKey();
            $copy->provider = $connection->provider;
            $copy->status = OrganizationBackupCloudCopy::STATUS_QUEUED;
            $copy->size_bytes = $backup->size_bytes;
            $copy->checksum = $backup->checksum;
            $copy->save();
        } catch (QueryException) {
            $copy = OrganizationBackupCloudCopy::query()
                ->where('organization_backup_id', $backup->getKey())
                ->where('provider', OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)
                ->first();
        }

        if ($copy && $copy->status !== OrganizationBackupCloudCopy::STATUS_COMPLETED) {
            $this->audit->record('organization_cloud_backup.sync_started', null, $organization, newValues: [
                'backup_uuid' => $backup->uuid,
                'provider' => $copy->provider,
            ]);
            SyncOrganizationBackupToPersonalCloudJob::dispatch($copy->getKey());
        }

        return $copy;
    }
}
