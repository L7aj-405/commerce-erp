<?php

namespace Tests\Feature\Settings;

use App\Actions\OrganizationBackups\ApplyOrganizationBackupRetentionAction;
use App\Actions\OrganizationBackups\DispatchDueOrganizationBackupsAction;
use App\Jobs\CreateScheduledOrganizationBackupJob;
use App\Mail\OrganizationBackupFailedMail;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupSetting;
use App\Models\User;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PlatformTestCase;

class OrganizationScheduledBackupTest extends PlatformTestCase
{
    public function test_scheduled_backup_settings_require_authorization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $sales = User::factory()->create();
        $this->addOrganizationMember($organization, $sales, ['organization_backups.view']);
        $this->activate($sales, $organization);

        $this->actingAs($sales)->put(route('organization-backups.settings.update'), [
            'enabled' => true,
            'frequency' => 'daily',
            'time_of_day' => '03:00',
            'timezone' => 'Africa/Casablanca',
            'retention_count' => 30,
            'notify_on_failure' => true,
        ])->assertForbidden();
    }

    public function test_due_organization_dispatches_one_job_using_organization_timezone(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'Africa/Casablanca'];
        $organization->save();

        $this->backupSetting($organization->id, [
            'enabled' => true,
            'frequency' => 'daily',
            'time_of_day' => '03:00:00',
            'timezone' => 'Africa/Casablanca',
        ]);

        $count = app(DispatchDueOrganizationBackupsAction::class)->execute(CarbonImmutable::parse('2026-09-26 03:01:00', 'Africa/Casablanca')->utc());

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('organization_backups', [
            'organization_id' => $organization->id,
            'type' => OrganizationBackup::TYPE_SCHEDULED,
            'status' => OrganizationBackup::STATUS_QUEUED,
        ]);
        Bus::assertDispatched(CreateScheduledOrganizationBackupJob::class);
    }

    public function test_disabled_schedule_does_nothing(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->backupSetting($organization->id, ['enabled' => false]);

        $count = app(DispatchDueOrganizationBackupsAction::class)->execute(CarbonImmutable::parse('2026-09-26 03:01:00', 'UTC'));

        $this->assertSame(0, $count);
        Bus::assertNotDispatched(CreateScheduledOrganizationBackupJob::class);
    }

    public function test_duplicate_scheduler_execution_does_not_duplicate_same_slot(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->backupSetting($organization->id, [
            'enabled' => true,
            'frequency' => 'daily',
            'time_of_day' => '03:00:00',
            'timezone' => 'UTC',
        ]);

        $now = CarbonImmutable::parse('2026-09-26 03:01:00', 'UTC');
        app(DispatchDueOrganizationBackupsAction::class)->execute($now);
        app(DispatchDueOrganizationBackupsAction::class)->execute($now);

        $this->assertSame(1, OrganizationBackup::query()->where('organization_id', $organization->id)->count());
        Bus::assertDispatched(CreateScheduledOrganizationBackupJob::class, 1);
    }

    public function test_scheduled_job_uploads_private_backup_and_marks_metadata_completed(): void
    {
        Storage::fake('organization_backups');

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $setting = $this->backupSetting($organization->id, ['enabled' => true]);
        $backup = $this->queuedBackup($organization->id);

        (new CreateScheduledOrganizationBackupJob($backup->id))->handle(
            app(OrganizationBackupExporter::class),
            app(\App\Services\OrganizationBackups\OrganizationBackupValidator::class),
            app(\App\Services\OrganizationBackups\OrganizationBackupStorage::class),
            app(ApplyOrganizationBackupRetentionAction::class),
            app(\App\Actions\OrganizationBackups\QueuePersonalCloudBackupSyncAction::class),
            app(\App\Services\AuditLogger::class),
        );

        $backup->refresh();
        $setting->refresh();

        $this->assertSame(OrganizationBackup::STATUS_COMPLETED, $backup->status);
        $this->assertNotNull($backup->checksum);
        $this->assertNotNull($backup->storage_path);
        Storage::disk('organization_backups')->assertExists($backup->storage_path);
        $this->assertNotNull($setting->last_success_at);
    }

    public function test_retention_keeps_configured_count_and_never_deletes_newest_success(): void
    {
        config(['organization-backups.retention_min' => 1]);
        Storage::fake('organization_backups');

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $oldest = $this->completedBackup($organization->id, 'old.erpbackup', now()->subDays(3));
        $middle = $this->completedBackup($organization->id, 'middle.erpbackup', now()->subDays(2));
        $newest = $this->completedBackup($organization->id, 'new.erpbackup', now()->subDay());
        Storage::disk('organization_backups')->put($oldest->storage_path, 'old');
        Storage::disk('organization_backups')->put($middle->storage_path, 'middle');
        Storage::disk('organization_backups')->put($newest->storage_path, 'new');

        app(ApplyOrganizationBackupRetentionAction::class)->execute($organization, 2);

        $this->assertSame(OrganizationBackup::STATUS_DELETED_BY_RETENTION, $oldest->fresh()->status);
        $this->assertSame(OrganizationBackup::STATUS_COMPLETED, $middle->fresh()->status);
        $this->assertSame(OrganizationBackup::STATUS_COMPLETED, $newest->fresh()->status);
        Storage::disk('organization_backups')->assertMissing('old.erpbackup');
        Storage::disk('organization_backups')->assertExists('new.erpbackup');
    }

    public function test_history_download_is_tenant_scoped(): void
    {
        Storage::fake('organization_backups');

        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $this->activate($ownerB, $organizationB);

        $backup = $this->completedBackup($organizationA->id, 'tenant-a.erpbackup', now());
        Storage::disk('organization_backups')->put($backup->storage_path, 'not-a-real-backup');

        $this->actingAs($ownerB)
            ->get(route('organization-backups.history.download', $backup))
            ->assertNotFound();
    }

    public function test_restore_from_history_still_requires_validation_and_confirmation(): void
    {
        Storage::fake('organization_backups');

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $backup = $this->completedBackup($organization->id, 'valid.erpbackup', now());
        Storage::disk('organization_backups')->put($backup->storage_path, file_get_contents($archive->path));

        $this->actingAs($owner)
            ->post(route('organization-backups.history.validate', $backup))
            ->assertRedirect();

        $this->assertNotNull(session('organization_backup.validated.token'));
        $this->assertSame('private', session('organization_backup.validated.source'));
        $this->assertSame('Sauvegarde privée', session('organization_backup.validated.source_label'));
    }

    public function test_failure_notification_is_sent_once_after_final_failure(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->backupSetting($organization->id, [
            'enabled' => true,
            'notify_on_failure' => true,
        ]);
        $backup = $this->queuedBackup($organization->id);
        $backup->status = OrganizationBackup::STATUS_FAILED;
        $backup->failure_message = 'upload_failed';
        $backup->save();

        Mail::to($owner->email)->send(new OrganizationBackupFailedMail($organization, $backup, 'upload_failed'));
        $backup->notified_failure_at = now();
        $backup->save();

        Mail::assertSent(OrganizationBackupFailedMail::class, 1);
        $this->assertNotNull($backup->fresh()->notified_failure_at);
    }

    /** @param array<string, mixed> $overrides */
    private function backupSetting(int $organizationId, array $overrides = []): OrganizationBackupSetting
    {
        $setting = new OrganizationBackupSetting;
        $setting->organization_id = $organizationId;
        $setting->enabled = $overrides['enabled'] ?? true;
        $setting->frequency = $overrides['frequency'] ?? 'daily';
        $setting->time_of_day = $overrides['time_of_day'] ?? '03:00:00';
        $setting->day_of_week = $overrides['day_of_week'] ?? 1;
        $setting->timezone = $overrides['timezone'] ?? 'UTC';
        $setting->retention_count = $overrides['retention_count'] ?? 30;
        $setting->notify_on_failure = $overrides['notify_on_failure'] ?? false;
        $setting->save();

        return $setting;
    }

    private function queuedBackup(int $organizationId): OrganizationBackup
    {
        $backup = new OrganizationBackup;
        $backup->organization_id = $organizationId;
        $backup->uuid = (string) \Illuminate\Support\Str::uuid();
        $backup->type = OrganizationBackup::TYPE_SCHEDULED;
        $backup->status = OrganizationBackup::STATUS_QUEUED;
        $backup->scheduled_for = now();
        $backup->scheduled_slot = 'test-slot-'.\Illuminate\Support\Str::random(8);
        $backup->save();

        return $backup;
    }

    private function completedBackup(int $organizationId, string $path, mixed $completedAt): OrganizationBackup
    {
        $backup = $this->queuedBackup($organizationId);
        $backup->status = OrganizationBackup::STATUS_COMPLETED;
        $backup->completed_at = $completedAt;
        $backup->size_bytes = 123;
        $backup->checksum = hash('sha256', $path);
        $backup->storage_disk = 'organization_backups';
        $backup->storage_path = $path;
        $backup->format_version = 1;
        $backup->save();

        return $backup;
    }
}
