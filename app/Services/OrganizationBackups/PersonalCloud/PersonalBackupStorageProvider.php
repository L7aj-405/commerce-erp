<?php

namespace App\Services\OrganizationBackups\PersonalCloud;

use App\Models\OrganizationBackupCloudCopy;
use App\Models\OrganizationCloudBackupConnection;

interface PersonalBackupStorageProvider
{
    public function provider(): string;

    public function authorizationUrl(string $state): string;

    /** @return array<string, mixed> */
    public function exchangeAuthorizationCode(string $code): array;

    public function refreshIfNeeded(OrganizationCloudBackupConnection $connection): OrganizationCloudBackupConnection;

    public function ensureBackupFolder(OrganizationCloudBackupConnection $connection): string;

    public function upload(OrganizationCloudBackupConnection $connection, OrganizationBackupCloudCopy $copy, string $localPath, string $filename): string;

    public function download(OrganizationCloudBackupConnection $connection, string $providerFileId, string $targetPath): void;

    public function test(OrganizationCloudBackupConnection $connection): void;

    public function revoke(OrganizationCloudBackupConnection $connection): void;
}
