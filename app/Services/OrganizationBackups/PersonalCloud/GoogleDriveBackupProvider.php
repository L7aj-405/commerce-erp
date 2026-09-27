<?php

namespace App\Services\OrganizationBackups\PersonalCloud;

use App\Models\OrganizationBackupCloudCopy;
use App\Models\OrganizationCloudBackupConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleDriveBackupProvider implements PersonalBackupStorageProvider
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const DRIVE_URL = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    public function provider(): string
    {
        return OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE;
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => config('services.google_drive.scope', 'https://www.googleapis.com/auth/drive.file'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new PersonalCloudBackupException('Connexion Google Drive refusée.');
        }

        return $response->json();
    }

    public function refreshIfNeeded(OrganizationCloudBackupConnection $connection): OrganizationCloudBackupConnection
    {
        if ($connection->token_expires_at && $connection->token_expires_at->isFuture() && filled($connection->access_token)) {
            return $connection;
        }

        if (! filled($connection->refresh_token)) {
            throw new PersonalCloudBackupException('Connexion expirée — reconnectez Google Drive.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        if (! $response->successful()) {
            $connection->is_enabled = false;
            $connection->last_sync_status = 'reauthorization_required';
            $connection->last_sync_error = 'Connexion expirée — reconnectez Google Drive.';
            $connection->save();

            throw new PersonalCloudBackupException('Connexion expirée — reconnectez Google Drive.');
        }

        $payload = $response->json();
        $connection->access_token = $payload['access_token'] ?? null;
        $connection->token_expires_at = now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 3600) - 60));
        $connection->token_payload = [
            'scope' => $payload['scope'] ?? null,
            'token_type' => $payload['token_type'] ?? null,
        ];
        $connection->save();

        return $connection->fresh();
    }

    public function ensureBackupFolder(OrganizationCloudBackupConnection $connection): string
    {
        $connection = $this->refreshIfNeeded($connection);
        if (filled($connection->provider_folder_id) && $this->folderExists($connection, $connection->provider_folder_id)) {
            return $connection->provider_folder_id;
        }

        $rootId = $this->findOrCreateFolder($connection, '10xScale ERP', null);
        $backupId = $this->findOrCreateFolder($connection, 'Sauvegardes', $rootId);

        $connection->provider_folder_id = $backupId;
        $connection->save();

        return $backupId;
    }

    public function upload(OrganizationCloudBackupConnection $connection, OrganizationBackupCloudCopy $copy, string $localPath, string $filename): string
    {
        $connection = $this->refreshIfNeeded($connection);
        $folderId = $this->ensureBackupFolder($connection);
        $metadata = [
            'name' => $filename,
            'parents' => [$folderId],
            'description' => '10xScale ERP organization backup '.$copy->organizationBackup?->uuid,
            'appProperties' => [
                'commerce_erp_backup_uuid' => $copy->organizationBackup?->uuid,
                'organization_id' => (string) $copy->organization_id,
            ],
        ];

        $session = Http::withToken($connection->access_token)
            ->withHeaders([
                'X-Upload-Content-Type' => 'application/octet-stream',
                'X-Upload-Content-Length' => (string) filesize($localPath),
            ])
            ->post(self::UPLOAD_URL.'?uploadType=resumable&fields=id', $metadata);

        if (! $session->successful() || ! $session->header('Location')) {
            throw new PersonalCloudBackupException('Impossible de préparer l’envoi Google Drive.');
        }

        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new PersonalCloudBackupException('Impossible de lire la sauvegarde locale.');
        }

        try {
            $upload = Http::withHeaders([
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) filesize($localPath),
            ])->send('PUT', $session->header('Location'), ['body' => $handle]);
        } finally {
            fclose($handle);
        }

        if (! $upload->successful()) {
            throw new PersonalCloudBackupException('Échec de synchronisation Google Drive.');
        }

        return (string) ($upload->json('id') ?? '');
    }

    public function download(OrganizationCloudBackupConnection $connection, string $providerFileId, string $targetPath): void
    {
        $connection = $this->refreshIfNeeded($connection);
        $response = Http::withToken($connection->access_token)
            ->sink($targetPath)
            ->get(self::DRIVE_URL.'/files/'.rawurlencode($providerFileId), ['alt' => 'media']);

        if (! $response->successful()) {
            @unlink($targetPath);
            throw new PersonalCloudBackupException('Impossible de télécharger la sauvegarde depuis Google Drive.');
        }
    }

    public function test(OrganizationCloudBackupConnection $connection): void
    {
        $connection = $this->refreshIfNeeded($connection);
        $folderId = $this->ensureBackupFolder($connection);
        if (! $this->folderExists($connection, $folderId)) {
            throw new PersonalCloudBackupException('Dossier Google Drive inaccessible.');
        }
    }

    public function revoke(OrganizationCloudBackupConnection $connection): void
    {
        if (filled($connection->access_token)) {
            Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $connection->access_token]);
        }
    }

    private function folderExists(OrganizationCloudBackupConnection $connection, string $folderId): bool
    {
        $response = Http::withToken($connection->access_token)
            ->get(self::DRIVE_URL.'/files/'.rawurlencode($folderId), [
                'fields' => 'id,mimeType,trashed',
            ]);

        if (! $response->successful()) {
            $this->reportGoogleFailure('folder_exists', $response);

            return false;
        }

        return $response->successful()
            && $response->json('mimeType') === 'application/vnd.google-apps.folder'
            && $response->json('trashed') !== true;
    }

    private function findOrCreateFolder(OrganizationCloudBackupConnection $connection, string $name, ?string $parentId): string
    {
        $query = sprintf(
            "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false%s",
            str_replace("'", "\\'", $name),
            $parentId ? " and '{$parentId}' in parents" : '',
        );

        $existing = Http::withToken($connection->access_token)->get(self::DRIVE_URL.'/files', [
            'q' => $query,
            'fields' => 'files(id,name)',
            'pageSize' => 1,
        ]);

        if (! $existing->successful()) {
            $this->throwGoogleFailure('folder_lookup', $existing, 'Impossible de préparer le dossier Google Drive.');
        }

        if (filled($existing->json('files.0.id'))) {
            return (string) $existing->json('files.0.id');
        }

        $metadata = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        if ($parentId) {
            $metadata['parents'] = [$parentId];
        }

        $created = Http::withToken($connection->access_token)->post(self::DRIVE_URL.'/files?fields=id', $metadata);
        if (! $created->successful() || ! filled($created->json('id'))) {
            $this->throwGoogleFailure('folder_create', $created, 'Impossible de préparer le dossier Google Drive.');
        }

        return (string) $created->json('id');
    }

    private function throwGoogleFailure(string $operation, Response $response, string $userMessage): never
    {
        $this->reportGoogleFailure($operation, $response);

        throw new PersonalCloudBackupException($userMessage);
    }

    private function reportGoogleFailure(string $operation, Response $response): void
    {
        Log::warning('google_drive.backup_provider_request_failed', [
            'operation' => $operation,
            'http_status' => $response->status(),
            'google_error_code' => $response->json('error.code'),
            'google_error_status' => $response->json('error.status'),
            'google_error_reason' => $response->json('error.errors.0.reason'),
            'google_error_message' => $this->sanitizeGoogleMessage($response->json('error.message')),
        ]);
    }

    private function sanitizeGoogleMessage(mixed $message): ?string
    {
        if (! is_string($message) || $message === '') {
            return null;
        }

        $sanitized = Str::of($message)
            ->replaceMatches('/Bearer\\s+[A-Za-z0-9._~+\\/-]+=*/i', '[redacted-bearer-token]');

        if ($this->clientSecret() !== '') {
            $sanitized = $sanitized->replace($this->clientSecret(), '[redacted-client-secret]');
        }

        return $sanitized->limit(300)->toString();
    }

    private function clientId(): string
    {
        return (string) config('services.google_drive.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.google_drive.client_secret');
    }

    private function redirectUri(): string
    {
        return (string) config('services.google_drive.redirect_uri');
    }
}
