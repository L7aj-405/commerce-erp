<?php

namespace App\Services\OrganizationBackups;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use ZipArchive;

class OrganizationBackupValidator
{
    public function __construct(private readonly OrganizationBackupSchema $schema) {}

    /** @return array{token:string, path:string, manifest:array<string, mixed>, summary:array<string, mixed>} */
    public function validateUpload(UploadedFile $file, Organization $organization): array
    {
        $this->assertExtension($file);

        $directory = storage_path('app/private/organization-backups/validated');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $token = (string) Str::uuid();
        $path = $directory.DIRECTORY_SEPARATOR.$token.'.erpbackup';
        $file->move($directory, basename($path));

        try {
            $result = $this->validatePath($path, $organization);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return ['token' => $token, 'path' => $path, ...$result];
    }

    /** @return array{token:string, path:string, manifest:array<string, mixed>, summary:array<string, mixed>} */
    public function stageValidatedPath(string $sourcePath, Organization $organization): array
    {
        $directory = storage_path('app/private/organization-backups/validated');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $token = (string) Str::uuid();
        $path = $directory.DIRECTORY_SEPARATOR.$token.'.erpbackup';
        if (! copy($sourcePath, $path)) {
            throw new OrganizationBackupException('Impossible de préparer la sauvegarde pour restauration.');
        }

        try {
            $result = $this->validatePath($path, $organization);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return ['token' => $token, 'path' => $path, ...$result];
    }

    /** @return array{manifest:array<string, mixed>, summary:array<string, mixed>} */
    public function validatePath(string $path, Organization $organization): array
    {
        $zip = $this->open($path);
        try {
            $this->assertSafePaths($zip);
            $manifest = $this->manifest($zip);
            if (! OrganizationBackupManifest::supports($manifest)) {
                throw new OrganizationBackupException('Format de sauvegarde non pris en charge.');
            }
            if ((int) ($manifest['source_organization_id'] ?? 0) !== (int) $organization->getKey()) {
                throw new OrganizationBackupException('Cette sauvegarde appartient à une autre organisation.');
            }
            $tables = $manifest['tables'] ?? [];
            if (! is_array($tables) || ! in_array('organizations', $tables, true)) {
                throw new OrganizationBackupException('La sauvegarde ne contient pas les jeux de données requis.');
            }
            foreach ($tables as $table) {
                if (! is_string($table) || $zip->locateName("data/{$table}.jsonl") === false) {
                    throw new OrganizationBackupException('La sauvegarde est incomplète.');
                }
            }
            $checksum = $this->checksum($zip, $tables);
            if (! hash_equals((string) ($manifest['checksum'] ?? ''), $checksum)) {
                throw new OrganizationBackupException('L’intégrité de la sauvegarde ne peut pas être vérifiée.');
            }

            return [
                'manifest' => $manifest,
                'summary' => $this->summary($manifest),
            ];
        } finally {
            $zip->close();
        }
    }

    public function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path) !== true) {
            throw new OrganizationBackupException('Archive de sauvegarde invalide ou illisible.');
        }

        return $zip;
    }

    private function assertExtension(UploadedFile $file): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'erpbackup') {
            throw new OrganizationBackupException('Le fichier doit être une sauvegarde .erpbackup.');
        }
    }

    private function assertSafePaths(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_starts_with($name, '/') || str_starts_with($name, '\\')
                || str_contains($name, '..') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $name)) {
                throw new OrganizationBackupException('La sauvegarde contient un chemin d’archive non sécurisé.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function manifest(ZipArchive $zip): array
    {
        $json = $zip->getFromName('manifest.json');
        if (! is_string($json) || $json === '') {
            throw new OrganizationBackupException('Manifeste de sauvegarde manquant.');
        }
        $manifest = json_decode($json, true);
        if (! is_array($manifest)) {
            throw new OrganizationBackupException('Manifeste de sauvegarde malformé.');
        }

        return $manifest;
    }

    /** @param list<string> $tables */
    private function checksum(ZipArchive $zip, array $tables): string
    {
        $hash = hash_init('sha256');
        foreach ($tables as $table) {
            $stream = $zip->getStream("data/{$table}.jsonl");
            if (! is_resource($stream)) {
                throw new OrganizationBackupException('La sauvegarde est incomplète.');
            }
            while (($line = fgets($stream)) !== false) {
                hash_update($hash, $table."\0".$line);
                json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
            fclose($stream);
        }

        return hash_final($hash);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function summary(array $manifest): array
    {
        $counts = is_array($manifest['counts'] ?? null) ? $manifest['counts'] : [];

        return [
            'organization' => $manifest['source_organization_name'] ?? '—',
            'created_at' => $manifest['created_at'] ?? null,
            'version' => $manifest['version'] ?? null,
            'products' => $counts['products'] ?? 0,
            'customers' => $counts['customers'] ?? 0,
            'orders' => $counts['sales_orders'] ?? 0,
            'invoices' => $counts['invoices'] ?? 0,
            'payments' => $counts['payments'] ?? 0,
            'inventory_movements' => $counts['inventory_movements'] ?? 0,
            'sensitive_columns' => $manifest['sensitive_columns'] ?? [],
        ];
    }
}
