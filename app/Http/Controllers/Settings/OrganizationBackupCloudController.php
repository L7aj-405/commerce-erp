<?php

namespace App\Http\Controllers\Settings;

use App\Actions\OrganizationBackups\QueuePersonalCloudBackupSyncAction;
use App\Http\Controllers\Controller;
use App\Jobs\SyncOrganizationBackupToPersonalCloudJob;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupCloudCopy;
use App\Models\OrganizationCloudBackupConnection;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\OrganizationBackups\OrganizationBackupException;
use App\Services\OrganizationBackups\OrganizationBackupValidator;
use App\Services\OrganizationBackups\PersonalCloud\PersonalBackupStorageManager;
use App\Services\OrganizationBackups\PersonalCloud\PersonalCloudBackupException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OrganizationBackupCloudController extends Controller
{
    public function connect(Request $request, ActiveTenantContext $context, PersonalBackupStorageManager $providers): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.create'), 403);

        $state = Str::random(48);
        session([
            'organization_backup.google_drive_oauth' => [
                'state' => $state,
                'organization_id' => $organization->getKey(),
            ],
        ]);

        return redirect()->away($providers->provider(OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)->authorizationUrl($state));
    }

    public function callback(
        Request $request,
        ActiveTenantContext $context,
        PersonalBackupStorageManager $providers,
        AuditLogger $audit,
    ): RedirectResponse {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.create'), 403);

        $session = session('organization_backup.google_drive_oauth');
        if (! is_array($session)
            || ! hash_equals((string) ($session['state'] ?? ''), (string) $request->query('state'))
            || (int) ($session['organization_id'] ?? 0) !== (int) $organization->getKey()) {
            return redirect()->route('organization-backups.index')->withErrors(['cloud' => 'Session Google Drive invalide. Réessayez la connexion.']);
        }
        session()->forget('organization_backup.google_drive_oauth');

        if (! $request->query('code')) {
            return redirect()->route('organization-backups.index')->withErrors(['cloud' => 'Connexion Google Drive annulée.']);
        }

        try {
            $provider = $providers->provider(OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE);
            $token = $provider->exchangeAuthorizationCode((string) $request->query('code'));

            $connection = OrganizationCloudBackupConnection::query()
                ->where('organization_id', $organization->getKey())
                ->where('provider', OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)
                ->first();
            if (! $connection) {
                $connection = new OrganizationCloudBackupConnection;
                $connection->organization_id = $organization->getKey();
                $connection->provider = OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE;
            }
            $connection->access_token = $token['access_token'] ?? null;
            $connection->refresh_token = $token['refresh_token'] ?? $connection->refresh_token;
            $connection->token_expires_at = now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600) - 60));
            $connection->token_payload = [
                'scope' => $token['scope'] ?? null,
                'token_type' => $token['token_type'] ?? null,
            ];
            $connection->provider_account_identifier = $this->safeGoogleAccountIdentifier((string) ($token['access_token'] ?? ''));
            $connection->connected_at = now();
            $connection->is_enabled = true;
            $connection->last_sync_status = null;
            $connection->last_sync_error = null;
            $connection->save();

            $provider->ensureBackupFolder($connection->fresh());

            $audit->record('organization_cloud_backup.connected', $request->user(), $organization, newValues: [
                'provider' => OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE,
            ]);

            return redirect()->route('organization-backups.index')->with('status', 'Google Drive connecté.');
        } catch (PersonalCloudBackupException $exception) {
            return redirect()->route('organization-backups.index')->withErrors(['cloud' => $exception->getMessage()]);
        }
    }

    public function test(Request $request, ActiveTenantContext $context, PersonalBackupStorageManager $providers): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.view'), 403);
        $connection = $this->googleConnection($organization->getKey());

        try {
            $providers->provider($connection->provider)->test($connection);
            $connection->last_sync_status = 'connection_ok';
            $connection->last_sync_error = null;
            $connection->save();

            return back()->with('status', 'Connexion Google Drive réussie.');
        } catch (PersonalCloudBackupException $exception) {
            $connection->last_sync_status = 'failed';
            $connection->last_sync_error = $exception->getMessage();
            $connection->save();

            return back()->withErrors(['cloud' => $exception->getMessage()]);
        }
    }

    public function disconnect(
        Request $request,
        ActiveTenantContext $context,
        PersonalBackupStorageManager $providers,
        AuditLogger $audit,
    ): RedirectResponse {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.create'), 403);
        $connection = $this->googleConnection($organization->getKey());

        try {
            $providers->provider($connection->provider)->revoke($connection);
        } catch (\Throwable) {
            // Local disconnect must still clear token material; Google files remain user-owned.
        }

        $connection->access_token = null;
        $connection->refresh_token = null;
        $connection->token_payload = null;
        $connection->token_expires_at = null;
        $connection->is_enabled = false;
        $connection->last_sync_status = 'disconnected';
        $connection->last_sync_error = null;
        $connection->save();

        $audit->record('organization_cloud_backup.disconnected', $request->user(), $organization, newValues: [
            'provider' => $connection->provider,
        ]);

        return back()->with('status', 'Google Drive déconnecté. Les fichiers déjà présents dans votre Drive ne sont pas supprimés.');
    }

    public function copy(
        Request $request,
        ActiveTenantContext $context,
        OrganizationBackup $organizationBackup,
        QueuePersonalCloudBackupSyncAction $action,
    ): RedirectResponse {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.create'), 403);
        abort_unless((int) $organizationBackup->organization_id === (int) $organization->getKey(), 404);
        abort_unless($organizationBackup->status === OrganizationBackup::STATUS_COMPLETED, 404);

        $copy = $action->execute($organizationBackup->fresh(['organization']));

        return back()->with('status', $copy ? 'Synchronisation Google Drive lancée.' : 'Google Drive n’est pas connecté.');
    }

    public function retry(Request $request, ActiveTenantContext $context, OrganizationBackupCloudCopy $cloudCopy): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.create'), 403);
        abort_unless((int) $cloudCopy->organization_id === (int) $organization->getKey(), 404);
        abort_unless($cloudCopy->status === OrganizationBackupCloudCopy::STATUS_FAILED, 404);

        $cloudCopy->status = OrganizationBackupCloudCopy::STATUS_QUEUED;
        $cloudCopy->failure_message = null;
        $cloudCopy->save();
        SyncOrganizationBackupToPersonalCloudJob::dispatch($cloudCopy->getKey());

        return back()->with('status', 'Synchronisation Google Drive relancée.');
    }

    public function validateCloudBackup(
        Request $request,
        ActiveTenantContext $context,
        OrganizationBackupCloudCopy $cloudCopy,
        PersonalBackupStorageManager $providers,
        OrganizationBackupValidator $validator,
        AuditLogger $audit,
    ): RedirectResponse {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'organization_backups.restore'), 403);
        abort_unless((int) $cloudCopy->organization_id === (int) $organization->getKey(), 404);
        abort_unless($cloudCopy->status === OrganizationBackupCloudCopy::STATUS_COMPLETED && filled($cloudCopy->provider_file_id), 404);

        $directory = storage_path('app/private/organization-backups/cloud-restore');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.$cloudCopy->getKey().'.erpbackup';

        try {
            $providers->provider($cloudCopy->provider)->download($cloudCopy->connection, $cloudCopy->provider_file_id, $path);
            $validated = $validator->stageValidatedPath($path, $organization);
            @unlink($path);

            session(['organization_backup.validated' => [
                'token' => $validated['token'],
                'source' => 'google_drive',
                'source_label' => 'Google Drive',
                'summary' => $validated['summary'],
                'manifest' => [
                    'created_at' => $validated['manifest']['created_at'] ?? null,
                    'version' => $validated['manifest']['version'] ?? null,
                    'source_organization_id' => $validated['manifest']['source_organization_id'] ?? null,
                    'source_organization_name' => $validated['manifest']['source_organization_name'] ?? null,
                ],
            ]]);

            $audit->record('organization_cloud_backup.restore_downloaded', $request->user(), $organization, newValues: [
                'provider' => $cloudCopy->provider,
                'cloud_copy_id' => $cloudCopy->getKey(),
            ]);

            return back()->with('status', 'Sauvegarde vérifiée. Confirmez la restauration pour continuer.');
        } catch (OrganizationBackupException|PersonalCloudBackupException $exception) {
            @unlink($path);

            return back()->withErrors(['cloud' => $exception->getMessage()]);
        }
    }

    private function googleConnection(int $organizationId): OrganizationCloudBackupConnection
    {
        return OrganizationCloudBackupConnection::query()
            ->where('organization_id', $organizationId)
            ->where('provider', OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE)
            ->firstOrFail();
    }

    private function safeGoogleAccountIdentifier(string $accessToken): ?string
    {
        if ($accessToken === '') {
            return null;
        }

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $accessToken]);

        return $response->successful() ? $response->json('email') : null;
    }
}
