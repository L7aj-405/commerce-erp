<?php

namespace App\Jobs;

use App\Actions\OrganizationBackups\ApplyOrganizationBackupRetentionAction;
use App\Actions\OrganizationBackups\QueuePersonalCloudBackupSyncAction;
use App\Mail\OrganizationBackupFailedMail;
use App\Models\OrganizationBackup;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use App\Services\OrganizationBackups\OrganizationBackupStorage;
use App\Services\OrganizationBackups\OrganizationBackupValidator;
use App\Support\SensitiveDataRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CreateScheduledOrganizationBackupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3900;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $backupId) {}

    public function uniqueId(): string
    {
        return 'scheduled-organization-backup:'.$this->backupId;
    }

    public function handle(
        OrganizationBackupExporter $exporter,
        OrganizationBackupValidator $validator,
        OrganizationBackupStorage $storage,
        ApplyOrganizationBackupRetentionAction $retention,
        QueuePersonalCloudBackupSyncAction $personalCloudSync,
        AuditLogger $audit,
    ): void {
        $backup = OrganizationBackup::query()->with('organization.backupSetting', 'organization.owner')->findOrFail($this->backupId);

        if ($backup->status === OrganizationBackup::STATUS_COMPLETED) {
            return;
        }

        $organization = $backup->organization;
        if (! $organization) {
            return;
        }

        $localPath = null;

        try {
            $backup->status = OrganizationBackup::STATUS_RUNNING;
            $backup->started_at ??= now();
            $backup->failure_message = null;
            $backup->save();

            $audit->record('organization_backup.started', null, $organization, newValues: [
                'backup_uuid' => $backup->uuid,
                'scheduled_for' => $backup->scheduled_for?->toIso8601String(),
            ]);

            $archive = $exporter->create($organization, null);
            $localPath = $archive->path;
            $validation = $validator->validatePath($localPath, $organization);
            $upload = $storage->upload($backup, $localPath);

            $backup->status = OrganizationBackup::STATUS_COMPLETED;
            $backup->completed_at = now();
            $backup->size_bytes = $upload['size'];
            $backup->checksum = (string) ($validation['manifest']['checksum'] ?? '');
            $backup->storage_disk = $upload['disk'];
            $backup->storage_path = $upload['path'];
            $backup->format_version = (int) ($validation['manifest']['version'] ?? 1);
            $backup->failure_message = null;
            $backup->save();

            $setting = $organization->backupSetting;
            if ($setting) {
                $setting->last_success_at = $backup->completed_at;
                $setting->last_failure_message = null;
                $setting->save();
            }

            $audit->record('organization_backup.completed', null, $organization, newValues: [
                'backup_uuid' => $backup->uuid,
                'size_bytes' => $backup->size_bytes,
                'checksum' => $backup->checksum,
            ]);

            $personalCloudSync->execute($backup->fresh(['organization']));
            $retention->execute($organization, (int) ($setting?->retention_count ?? 30));
        } catch (Throwable $exception) {
            $this->recordFailure($backup, $audit, $exception);

            if ($this->attempts() >= $this->tries) {
                $this->notifyFailure($backup);
            }

            throw $exception;
        } finally {
            if ($localPath && is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function recordFailure(OrganizationBackup $backup, AuditLogger $audit, Throwable $exception): void
    {
        $backup->refresh();
        $backup->status = $this->attempts() >= $this->tries
            ? OrganizationBackup::STATUS_FAILED
            : OrganizationBackup::STATUS_RUNNING;
        $backup->failure_message = $this->sanitizeFailure($exception);
        $backup->save();

        $organization = $backup->organization;
        if ($organization?->backupSetting) {
            $organization->backupSetting->last_failure_at = now();
            $organization->backupSetting->last_failure_message = $backup->failure_message;
            $organization->backupSetting->save();
        }

        if ($this->attempts() >= $this->tries && $organization) {
            $audit->record('organization_backup.failed', null, $organization, newValues: [
                'backup_uuid' => $backup->uuid,
                'reason' => $backup->failure_message,
            ]);
        }
    }

    private function notifyFailure(OrganizationBackup $backup): void
    {
        $backup->refresh();
        $organization = $backup->organization()->with('owner', 'backupSetting')->first();
        if (! $organization?->backupSetting?->notify_on_failure || ! $organization->owner?->email || $backup->notified_failure_at) {
            return;
        }

        Mail::to($organization->owner->email)->send(new OrganizationBackupFailedMail(
            $organization,
            $backup,
            $backup->failure_message ?: 'Échec de sauvegarde automatique.',
        ));

        $backup->notified_failure_at = now();
        $backup->save();
    }

    private function sanitizeFailure(Throwable $exception): string
    {
        return SensitiveDataRedactor::text($exception->getMessage(), 500) ?: 'scheduled_backup_failed';
    }
}
