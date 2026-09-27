<?php

namespace Tests\Feature\Settings;

use App\Actions\OrganizationBackups\QueuePersonalCloudBackupSyncAction;
use App\Jobs\SyncOrganizationBackupToPersonalCloudJob;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupCloudCopy;
use App\Models\OrganizationCloudBackupConnection;
use App\Models\User;
use App\Services\OrganizationBackups\OrganizationBackupExporter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PlatformTestCase;

class OrganizationGoogleDriveBackupTest extends PlatformTestCase
{
    public function test_authorized_user_can_start_google_oauth_with_state(): void
    {
        config(['services.google_drive.client_id' => 'client-id', 'services.google_drive.redirect_uri' => 'https://erp.test/callback']);
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $response = $this->actingAs($owner)->get(route('organization-backups.google-drive.connect'));

        $response->assertRedirect();
        $this->assertNotNull(session('organization_backup.google_drive_oauth.state'));
        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $response->headers->get('Location'));
        $this->assertStringContainsString(rawurlencode('https://www.googleapis.com/auth/drive.file'), $response->headers->get('Location'));
    }

    public function test_google_oauth_callback_rejects_invalid_state(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)
            ->get(route('organization-backups.google-drive.callback', ['state' => 'wrong', 'code' => 'abc']))
            ->assertSessionHasErrors('cloud');
    }

    public function test_google_oauth_callback_stores_tokens_encrypted_and_does_not_expose_them(): void
    {
        config(['services.google_drive.client_id' => 'client-id', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://erp.test/callback']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/drive.file',
            ]),
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response(['email' => 'client@example.com']),
            'https://www.googleapis.com/drive/v3/files*' => Http::sequence()
                ->push(['files' => []])
                ->push(['id' => 'root-folder'])
                ->push(['files' => []])
                ->push(['id' => 'backup-folder']),
        ]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        session(['organization_backup.google_drive_oauth' => ['state' => 'state-ok', 'organization_id' => $organization->id]]);

        $this->actingAs($owner)
            ->get(route('organization-backups.google-drive.callback', ['state' => 'state-ok', 'code' => 'auth-code']))
            ->assertRedirect(route('organization-backups.index'));

        $connection = OrganizationCloudBackupConnection::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('access-token', $connection->access_token);
        $this->assertSame('refresh-token', $connection->refresh_token);
        $this->assertSame([
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'token_type' => null,
        ], $connection->token_payload);
        $rawAccessToken = $connection->getRawOriginal('access_token');
        $rawRefreshToken = $connection->getRawOriginal('refresh_token');
        $rawTokenPayload = $connection->getRawOriginal('token_payload');
        $this->assertIsString($rawTokenPayload);
        $this->assertNotSame('access-token', $rawAccessToken);
        $this->assertNotSame('refresh-token', $connection->getRawOriginal('refresh_token'));
        $this->assertStringNotContainsString('access-token', $rawAccessToken);
        $this->assertStringNotContainsString('refresh-token', $rawRefreshToken);
        $this->assertStringNotContainsString('drive.file', $rawTokenPayload);
        $this->assertStringNotContainsString('https://www.googleapis.com/auth/drive.file', $rawTokenPayload);

        $page = $this->actingAs($owner)->get(route('organization-backups.index'));
        $page->assertOk();
        $page->assertDontSee('refresh-token');
        $page->assertDontSee('access-token');
    }

    public function test_google_oauth_callback_updates_existing_connection_without_duplicate(): void
    {
        config(['services.google_drive.client_id' => 'client-id', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://erp.test/callback']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/drive.file',
            ]),
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response(['email' => 'new-client@example.com']),
            'https://www.googleapis.com/drive/v3/files/folder-id*' => Http::response([
                'id' => 'folder-id',
                'mimeType' => 'application/vnd.google-apps.folder',
                'trashed' => false,
            ]),
        ]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $connection = $this->googleConnection($organization->id);
        session(['organization_backup.google_drive_oauth' => ['state' => 'state-ok', 'organization_id' => $organization->id]]);

        $this->actingAs($owner)
            ->get(route('organization-backups.google-drive.callback', ['state' => 'state-ok', 'code' => 'auth-code']))
            ->assertRedirect(route('organization-backups.index'));

        $this->assertSame(1, OrganizationCloudBackupConnection::query()
            ->where('organization_id', $organization->id)
            ->where('provider', OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)
            ->count());
        $connection->refresh();
        $this->assertSame('new-access-token', $connection->access_token);
        $this->assertSame('new-refresh-token', $connection->refresh_token);
        $this->assertSame('new-client@example.com', $connection->provider_account_identifier);
    }

    public function test_tenant_cannot_disconnect_another_organization_google_connection(): void
    {
        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $this->activate($ownerB, $organizationB);

        $connection = $this->googleConnection($organizationA->id);

        $this->actingAs($ownerB)->delete(route('organization-backups.google-drive.disconnect'))->assertNotFound();
        $this->assertNotNull($connection->fresh()->refresh_token);
    }

    public function test_scheduled_backup_dispatches_personal_sync_after_primary_success(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->googleConnection($organization->id);
        $backup = $this->completedBackup($organization->id);

        $copy = app(QueuePersonalCloudBackupSyncAction::class)->execute($backup->fresh(['organization']));

        $this->assertNotNull($copy);
        $this->assertSame(OrganizationBackupCloudCopy::STATUS_QUEUED, $copy->status);
        Bus::assertDispatched(SyncOrganizationBackupToPersonalCloudJob::class);
    }

    public function test_primary_backup_remains_completed_if_google_sync_fails(): void
    {
        Storage::fake('organization_backups');
        Http::fake(['*' => Http::response([], 500)]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $backup = $this->completedBackup($organization->id);
        Storage::disk('organization_backups')->put($backup->storage_path, 'backup');
        $connection = $this->googleConnection($organization->id);
        $copy = $this->cloudCopy($backup, $connection);

        try {
            (new SyncOrganizationBackupToPersonalCloudJob($copy->id))->handle(
                app(\App\Services\OrganizationBackups\OrganizationBackupStorage::class),
                app(\App\Services\OrganizationBackups\PersonalCloud\PersonalBackupStorageManager::class),
                app(\App\Services\AuditLogger::class),
            );
        } catch (\Throwable) {
            // Expected: cloud sync failure must not rewrite the primary backup.
        }

        $this->assertSame(OrganizationBackup::STATUS_COMPLETED, $backup->fresh()->status);
    }

    public function test_restore_from_google_drive_download_uses_phase_one_validator(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $archive = app(OrganizationBackupExporter::class)->create($organization, $owner);
        $backup = $this->completedBackup($organization->id);
        $connection = $this->googleConnection($organization->id);
        $copy = $this->cloudCopy($backup, $connection, OrganizationBackupCloudCopy::STATUS_COMPLETED);
        $copy->provider_file_id = 'drive-file-id';
        $copy->save();

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/drive-file-id*' => Http::response(file_get_contents($archive->path), 200),
        ]);

        $this->actingAs($owner)
            ->post(route('organization-backups.cloud-copies.validate', $copy))
            ->assertRedirect();

        $this->assertNotNull(session('organization_backup.validated.token'));
        $this->assertSame('google_drive', session('organization_backup.validated.source'));
        $this->assertSame('Google Drive', session('organization_backup.validated.source_label'));
    }

    public function test_disconnect_clears_local_token_material_without_deleting_cloud_files(): void
    {
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 200)]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $connection = $this->googleConnection($organization->id);

        $this->actingAs($owner)->delete(route('organization-backups.google-drive.disconnect'))->assertRedirect();

        $connection->refresh();
        $this->assertFalse($connection->is_enabled);
        $this->assertNull($connection->refresh_token);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_google_drive_provider_creates_root_and_backup_folders(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::sequence()
                ->push(['files' => []])
                ->push(['id' => 'root-folder'])
                ->push(['files' => []])
                ->push(['id' => 'backup-folder']),
        ]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $connection = $this->googleConnection($organization->id);
        $connection->provider_folder_id = null;
        $connection->save();

        $folderId = app(\App\Services\OrganizationBackups\PersonalCloud\GoogleDriveBackupProvider::class)->ensureBackupFolder($connection->fresh());

        $this->assertSame('backup-folder', $folderId);
        $this->assertSame('backup-folder', $connection->fresh()->provider_folder_id);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/drive/v3/files')
            && $request['name'] === 'Commerce ERP'
            && $request['mimeType'] === 'application/vnd.google-apps.folder');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/drive/v3/files')
            && $request['name'] === 'Sauvegardes'
            && $request['parents'] === ['root-folder']);
    }

    public function test_google_drive_provider_reuses_persisted_folder_id(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/folder-id*' => Http::response([
                'id' => 'folder-id',
                'mimeType' => 'application/vnd.google-apps.folder',
                'trashed' => false,
            ]),
        ]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $connection = $this->googleConnection($organization->id);

        $folderId = app(\App\Services\OrganizationBackups\PersonalCloud\GoogleDriveBackupProvider::class)->ensureBackupFolder($connection);

        $this->assertSame('folder-id', $folderId);
        Http::assertSentCount(1);
    }

    public function test_google_drive_provider_refreshes_expired_token_before_folder_request(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/drive.file',
            ]),
            'https://www.googleapis.com/drive/v3/files/folder-id*' => Http::response([
                'id' => 'folder-id',
                'mimeType' => 'application/vnd.google-apps.folder',
                'trashed' => false,
            ]),
        ]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $connection = $this->googleConnection($organization->id);
        $connection->token_expires_at = now()->subMinute();
        $connection->save();

        app(\App\Services\OrganizationBackups\PersonalCloud\GoogleDriveBackupProvider::class)->ensureBackupFolder($connection->fresh());

        $this->assertSame('fresh-access-token', $connection->fresh()->access_token);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fresh-access-token'));
    }

    public function test_google_drive_provider_logs_sanitized_folder_api_failure(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('google_drive.backup_provider_request_failed', \Mockery::on(function (array $context) {
                return $context['operation'] === 'folder_lookup'
                    && $context['http_status'] === 403
                    && $context['google_error_reason'] === 'insufficientPermissions'
                    && ! str_contains((string) $context['google_error_message'], 'access-token')
                    && ! str_contains((string) $context['google_error_message'], 'client-secret');
            }));

        config(['services.google_drive.client_secret' => 'client-secret']);
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response([
                'error' => [
                    'code' => 403,
                    'status' => 'PERMISSION_DENIED',
                    'message' => 'Bearer access-token client-secret cannot list files.',
                    'errors' => [['reason' => 'insufficientPermissions']],
                ],
            ], 403),
        ]);

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $connection = $this->googleConnection($organization->id);
        $connection->provider_folder_id = null;
        $connection->save();

        $this->expectException(\App\Services\OrganizationBackups\PersonalCloud\PersonalCloudBackupException::class);

        app(\App\Services\OrganizationBackups\PersonalCloud\GoogleDriveBackupProvider::class)->ensureBackupFolder($connection->fresh());
    }

    private function googleConnection(int $organizationId): OrganizationCloudBackupConnection
    {
        $connection = new OrganizationCloudBackupConnection;
        $connection->organization_id = $organizationId;
        $connection->provider = OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE;
        $connection->provider_account_identifier = 'client@example.com';
        $connection->access_token = 'access-token';
        $connection->refresh_token = 'refresh-token';
        $connection->token_expires_at = now()->addHour();
        $connection->provider_folder_id = 'folder-id';
        $connection->connected_at = now();
        $connection->is_enabled = true;
        $connection->save();

        return $connection;
    }

    private function completedBackup(int $organizationId): OrganizationBackup
    {
        $backup = new OrganizationBackup;
        $backup->organization_id = $organizationId;
        $backup->uuid = (string) \Illuminate\Support\Str::uuid();
        $backup->type = OrganizationBackup::TYPE_SCHEDULED;
        $backup->status = OrganizationBackup::STATUS_COMPLETED;
        $backup->scheduled_for = now();
        $backup->scheduled_slot = 'slot-'.\Illuminate\Support\Str::random(8);
        $backup->completed_at = now();
        $backup->size_bytes = 123;
        $backup->checksum = hash('sha256', 'backup');
        $backup->storage_disk = 'organization_backups';
        $backup->storage_path = $backup->uuid.'.erpbackup';
        $backup->format_version = 1;
        $backup->save();

        return $backup;
    }

    private function cloudCopy(OrganizationBackup $backup, OrganizationCloudBackupConnection $connection, string $status = OrganizationBackupCloudCopy::STATUS_QUEUED): OrganizationBackupCloudCopy
    {
        $copy = new OrganizationBackupCloudCopy;
        $copy->organization_backup_id = $backup->id;
        $copy->organization_id = $backup->organization_id;
        $copy->connection_id = $connection->id;
        $copy->provider = $connection->provider;
        $copy->status = $status;
        $copy->size_bytes = $backup->size_bytes;
        $copy->checksum = $backup->checksum;
        $copy->save();

        return $copy;
    }
}
