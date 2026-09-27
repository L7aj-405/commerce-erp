<?php

namespace App\Services\OrganizationBackups;

use App\Models\OrganizationBackup;
use Illuminate\Support\Facades\Storage;

class OrganizationBackupStorage
{
    public function disk(): string
    {
        return config('organization-backups.disk', 'organization_backups');
    }

    public function pathFor(OrganizationBackup $backup): string
    {
        $date = ($backup->scheduled_for ?? now())->timezone('UTC');

        return sprintf(
            'organization-backups/%s/%s/%s/%s.erpbackup',
            $backup->uuid,
            $date->format('Y'),
            $date->format('m'),
            $backup->uuid,
        );
    }

    public function upload(OrganizationBackup $backup, string $localPath): array
    {
        $disk = $this->disk();
        $path = $this->pathFor($backup);
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new OrganizationBackupException('Impossible de lire la sauvegarde locale.');
        }

        try {
            Storage::disk($disk)->put($path, $stream, ['visibility' => 'private']);
        } finally {
            fclose($stream);
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw new OrganizationBackupException('La sauvegarde externe n’a pas pu être vérifiée.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'size' => Storage::disk($disk)->size($path),
        ];
    }

    public function downloadToTemporaryFile(OrganizationBackup $backup, string $targetPath): void
    {
        if (! $backup->storage_disk || ! $backup->storage_path) {
            throw new OrganizationBackupException('La sauvegarde n’est pas disponible en stockage externe.');
        }

        $stream = Storage::disk($backup->storage_disk)->readStream($backup->storage_path);
        if (! is_resource($stream)) {
            throw new OrganizationBackupException('Impossible de lire la sauvegarde externe.');
        }

        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new OrganizationBackupException('Impossible de préparer la sauvegarde pour téléchargement.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }
    }

    public function delete(OrganizationBackup $backup): void
    {
        if (! $backup->storage_disk || ! $backup->storage_path) {
            return;
        }

        Storage::disk($backup->storage_disk)->delete($backup->storage_path);
        if (Storage::disk($backup->storage_disk)->exists($backup->storage_path)) {
            throw new OrganizationBackupException('La suppression externe de la sauvegarde a échoué.');
        }
    }
}
