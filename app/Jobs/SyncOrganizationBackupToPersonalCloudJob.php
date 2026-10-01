<?php

namespace App\Jobs;

use App\Models\OrganizationBackupCloudCopy;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupStorage;
use App\Services\OrganizationBackups\PersonalCloud\PersonalBackupStorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\SensitiveDataRedactor;
use Throwable;

class SyncOrganizationBackupToPersonalCloudJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3900;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $cloudCopyId) {}

    public function uniqueId(): string
    {
        return 'organization-backup-cloud-copy:'.$this->cloudCopyId;
    }

    public function handle(
        OrganizationBackupStorage $storage,
        PersonalBackupStorageManager $providers,
        AuditLogger $audit,
    ): void {
        $copy = OrganizationBackupCloudCopy::query()
            ->with('organization', 'organizationBackup', 'connection')
            ->findOrFail($this->cloudCopyId);

        if ($copy->status === OrganizationBackupCloudCopy::STATUS_COMPLETED) {
            return;
        }

        $backup = $copy->organizationBackup;
        $connection = $copy->connection;
        $organization = $copy->organization;
        if (! $backup || ! $connection || ! $organization || ! $connection->is_enabled) {
            return;
        }

        $directory = storage_path('app/private/organization-backups/cloud-sync');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $localPath = $directory.DIRECTORY_SEPARATOR.$copy->getKey().'.erpbackup';

        try {
            $copy->status = OrganizationBackupCloudCopy::STATUS_UPLOADING;
            $copy->started_at ??= now();
            $copy->failure_message = null;
            $copy->save();

            $storage->downloadToTemporaryFile($backup, $localPath);
            $filename = $this->filename($backup->uuid);
            $providerFileId = $providers->provider($copy->provider)->upload($connection, $copy, $localPath, $filename);

            $copy->provider_file_id = $providerFileId;
            $copy->status = OrganizationBackupCloudCopy::STATUS_COMPLETED;
            $copy->completed_at = now();
            $copy->size_bytes = filesize($localPath) ?: $backup->size_bytes;
            $copy->checksum = $backup->checksum;
            $copy->failure_message = null;
            $copy->save();

            $connection->last_sync_at = $copy->completed_at;
            $connection->last_sync_status = 'completed';
            $connection->last_sync_error = null;
            $connection->save();

            $audit->record('organization_cloud_backup.synced', null, $organization, newValues: [
                'backup_uuid' => $backup->uuid,
                'provider' => $copy->provider,
                'cloud_copy_id' => $copy->getKey(),
            ]);
        } catch (Throwable $exception) {
            $copy->refresh();
            $copy->status = $this->attempts() >= $this->tries
                ? OrganizationBackupCloudCopy::STATUS_FAILED
                : OrganizationBackupCloudCopy::STATUS_QUEUED;
            $copy->failure_message = $this->sanitizeFailure($exception);
            $copy->save();

            $connection->last_sync_status = 'failed';
            $connection->last_sync_error = $copy->failure_message;
            $connection->save();

            if ($this->attempts() >= $this->tries) {
                $audit->record('organization_cloud_backup.sync_failed', null, $organization, newValues: [
                    'backup_uuid' => $backup->uuid,
                    'provider' => $copy->provider,
                    'reason' => $copy->failure_message,
                ]);
            }

            throw $exception;
        } finally {
            if (is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function filename(string $backupUuid): string
    {
        return 'Commerce_ERP_'.$backupUuid.'_'.now()->format('Y-m-d_His').'.erpbackup';
    }

    private function sanitizeFailure(Throwable $exception): string
    {
        return SensitiveDataRedactor::text($exception->getMessage(), 500) ?: 'google_drive_sync_failed';
    }
}
