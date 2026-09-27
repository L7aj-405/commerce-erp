<?php

namespace App\Services\OrganizationBackups\PersonalCloud;

use App\Models\OrganizationCloudBackupConnection;

class PersonalBackupStorageManager
{
    public function __construct(private readonly GoogleDriveBackupProvider $googleDrive) {}

    public function provider(string $provider): PersonalBackupStorageProvider
    {
        return match ($provider) {
            OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE => $this->googleDrive,
            default => throw new PersonalCloudBackupException('Fournisseur de stockage personnel non pris en charge.'),
        };
    }
}
