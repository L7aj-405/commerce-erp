<?php

namespace App\Services\OrganizationBackups;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use JsonException;
use ZipArchive;

class OrganizationBackupValidator
{
    public function __construct(
        private readonly OrganizationBackupSchema $schema,
        private readonly OrganizationBackupCryptography $cryptography,
    ) {}

    /** @return array{token:string, path:string, manifest:array<string, mixed>, summary:array<string, mixed>} */
    public function validateUpload(UploadedFile $file, Organization $organization): array
    {
        $this->assertExtension($file);
        $this->assertArchiveSize((int) $file->getSize());
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
        $this->assertArchiveSize(is_file($sourcePath) ? (int) filesize($sourcePath) : 0);
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
        $this->assertArchiveSize(is_file($path) ? (int) filesize($path) : 0);
        $zip = $this->open($path);
        try {
            $this->assertArchiveLimitsAndPaths($zip);
            $manifest = $this->manifest($zip);
            $legacy = $this->assertSupportedAndAuthentic($manifest);
            $tables = $this->assertManifestTables($zip, $manifest, $legacy);
            if ((int) ($manifest['source_organization_id'] ?? 0) !== (int) $organization->getKey()) {
                throw new OrganizationBackupException('Cette sauvegarde appartient à une autre organisation.');
            }
            if (! $legacy) {
                $this->assertEncryptedEntries($zip, ['manifest.json', ...array_map(fn (string $table) => "data/{$table}.jsonl", $tables)]);
            } else {
                // Explicit legacy mode validates the original full checksum,
                // but restores only the current business allowlist. Historical
                // RBAC, credentials and audit entries remain non-restorable.
                $manifest['restore_tables'] = array_values(array_filter(
                    $tables,
                    fn (string $table) => $this->schema->isRestorableTable($table),
                ));
            }

            $checksum = $this->validateDataAndChecksum($zip, $tables, $manifest, $organization);
            if (! hash_equals((string) ($manifest['checksum'] ?? ''), $checksum)) {
                throw new OrganizationBackupException('L’intégrité de la sauvegarde ne peut pas être vérifiée.');
            }

            return ['manifest' => $manifest, 'summary' => $this->summary($manifest)];
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
        $zip->setPassword($this->cryptography->encryptionPassword());

        return $zip;
    }

    private function assertExtension(UploadedFile $file): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'erpbackup') {
            throw new OrganizationBackupException('Le fichier doit être une sauvegarde .erpbackup.');
        }
    }

    private function assertArchiveSize(int $bytes): void
    {
        if ($bytes <= 0 || $bytes > $this->limit('archive_bytes')) {
            throw new OrganizationBackupException('La taille de l’archive de sauvegarde est invalide.');
        }
    }

    private function assertArchiveLimitsAndPaths(ZipArchive $zip): void
    {
        if ($zip->numFiles < 2 || $zip->numFiles > $this->limit('entries')) {
            throw new OrganizationBackupException('La sauvegarde contient un nombre de fichiers non autorisé.');
        }

        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! is_array($stat)) {
                throw new OrganizationBackupException('La sauvegarde contient une entrée illisible.');
            }
            $name = (string) ($stat['name'] ?? '');
            $this->assertSafeEntryName($name);
            $size = (int) ($stat['size'] ?? -1);
            $compressed = (int) ($stat['comp_size'] ?? -1);
            if ($size < 0 || $compressed < 0 || $size > $this->limit('entry_bytes')) {
                throw new OrganizationBackupException('La sauvegarde contient une entrée trop volumineuse.');
            }
            $uncompressed += $size;
            if ($uncompressed > $this->limit('uncompressed_bytes')) {
                throw new OrganizationBackupException('La taille décompressée de la sauvegarde dépasse la limite autorisée.');
            }
            if ($size > 0 && ($compressed === 0 || ($size / max(1, $compressed)) > $this->limit('compression_ratio'))) {
                throw new OrganizationBackupException('Le taux de compression de la sauvegarde est suspect.');
            }
        }

        $manifest = $zip->statName('manifest.json');
        if (! is_array($manifest) || (int) ($manifest['size'] ?? 0) > $this->limit('manifest_bytes')) {
            throw new OrganizationBackupException('Manifeste de sauvegarde manquant ou trop volumineux.');
        }
    }

    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || strlen($name) > 255 || str_contains($name, "\0") || str_contains($name, '\\')
            || str_starts_with($name, '/') || str_ends_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)
            || collect(explode('/', $name))->contains(fn (string $part) => $part === '' || $part === '.' || $part === '..')) {
            throw new OrganizationBackupException('La sauvegarde contient un chemin d’archive non sécurisé.');
        }
    }

    /** @return array<string, mixed> */
    private function manifest(ZipArchive $zip): array
    {
        $json = $zip->getFromName('manifest.json', $this->limit('manifest_bytes'));
        if (! is_string($json) || $json === '') {
            throw new OrganizationBackupException('Manifeste absent, chiffré avec une autre clé ou illisible.');
        }
        try {
            $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OrganizationBackupException('Manifeste de sauvegarde malformé.');
        }
        if (! is_array($manifest)) {
            throw new OrganizationBackupException('Manifeste de sauvegarde malformé.');
        }

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function assertSupportedAndAuthentic(array $manifest): bool
    {
        if (OrganizationBackupManifest::supports($manifest)) {
            if (($manifest['security']['signature_algorithm'] ?? null) !== 'hmac-sha256'
                || ($manifest['security']['encryption'] ?? null) !== 'zip-aes-256'
                || ! $this->cryptography->verify($manifest)) {
                throw new OrganizationBackupException('La signature cryptographique de la sauvegarde est invalide.');
            }

            return false;
        }
        if (OrganizationBackupManifest::isLegacyUnsigned($manifest)
            && (bool) config('organization-backups.allow_legacy_unsigned_restore', false)) {
            return true;
        }

        throw new OrganizationBackupException('Format non pris en charge ou sauvegarde historique non signée interdite.');
    }

    /** @param array<string, mixed> $manifest @return list<string> */
    private function assertManifestTables(ZipArchive $zip, array $manifest, bool $legacy): array
    {
        $tables = $manifest['tables'] ?? null;
        if (! is_array($tables) || ! array_is_list($tables) || ! in_array('organizations', $tables, true)
            || count($tables) !== count(array_unique($tables))) {
            throw new OrganizationBackupException('La sauvegarde ne contient pas les jeux de données requis.');
        }
        foreach ($tables as $table) {
            if (! is_string($table) || ! preg_match('/^[a-z0-9_]+$/', $table)) {
                throw new OrganizationBackupException('La sauvegarde contient une table non autorisée.');
            }
            $known = $legacy
                ? $this->schema->isKnownTenantTable($table)
                : $this->schema->isRestorableTable($table);
            if (! $known) {
                throw new OrganizationBackupException('La sauvegarde contient une table non autorisée.');
            }
        }

        $expected = ['manifest.json', ...array_map(fn (string $table) => "data/{$table}.jsonl", $tables)];
        $actual = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $actual[] = (string) $zip->getNameIndex($i);
        }
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new OrganizationBackupException('La sauvegarde contient des fichiers inattendus ou est incomplète.');
        }

        return $tables;
    }

    /** @param list<string> $entries */
    private function assertEncryptedEntries(ZipArchive $zip, array $entries): void
    {
        if (! defined(ZipArchive::class.'::EM_AES_256')) {
            throw new OrganizationBackupException('Le serveur ne prend pas en charge le chiffrement AES-256 des sauvegardes.');
        }

        $requiredMethod = (int) constant(ZipArchive::class.'::EM_AES_256');
        foreach ($entries as $entry) {
            $stat = $zip->statName($entry);
            if (! is_array($stat) || (int) ($stat['encryption_method'] ?? -1) !== $requiredMethod) {
                throw new OrganizationBackupException('Toutes les entrées de la sauvegarde v2 doivent utiliser le chiffrement AES-256.');
            }
        }
    }

    /** @param list<string> $tables @param array<string, mixed> $manifest */
    private function validateDataAndChecksum(ZipArchive $zip, array $tables, array $manifest, Organization $organization): string
    {
        $hash = hash_init('sha256');
        $manifestCounts = is_array($manifest['counts'] ?? null) ? $manifest['counts'] : [];
        foreach ($tables as $table) {
            $stream = $zip->getStream("data/{$table}.jsonl");
            if (! is_resource($stream)) {
                throw new OrganizationBackupException('La sauvegarde est incomplète ou ne peut pas être déchiffrée.');
            }
            $rows = 0;
            try {
                while (($line = fgets($stream, $this->limit('jsonl_line_bytes') + 2)) !== false) {
                    if (! str_ends_with($line, "\n") && ! feof($stream)) {
                        throw new OrganizationBackupException('Une ligne de sauvegarde dépasse la taille autorisée.');
                    }
                    if (trim($line) === '') {
                        throw new OrganizationBackupException('Jeu de données de sauvegarde malformé.');
                    }
                    try {
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        throw new OrganizationBackupException('Jeu de données de sauvegarde malformé.');
                    }
                    if (! is_array($row) || ! $this->schema->hasOnlyKnownColumns($table, array_keys($row))) {
                        throw new OrganizationBackupException('La sauvegarde contient une colonne non autorisée.');
                    }
                    $this->assertTenantRow($table, $row, $organization);
                    hash_update($hash, $table."\0".$line);
                    $rows++;
                    if ($rows > $this->limit('rows_per_table')) {
                        throw new OrganizationBackupException('La sauvegarde contient trop de lignes pour une table.');
                    }
                }
            } finally {
                fclose($stream);
            }
            if (! array_key_exists($table, $manifestCounts) || (int) $manifestCounts[$table] !== $rows) {
                throw new OrganizationBackupException('Le nombre de lignes de la sauvegarde est incohérent.');
            }
        }

        return hash_final($hash);
    }

    /** @param array<string, mixed> $row */
    private function assertTenantRow(string $table, array $row, Organization $organization): void
    {
        $key = $table === 'organizations' ? 'id' : 'organization_id';
        if ((int) ($row[$key] ?? 0) !== (int) $organization->getKey()) {
            throw new OrganizationBackupException('La sauvegarde contient des données hors organisation.');
        }
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

    private function limit(string $key): int
    {
        return max(1, (int) config('organization-backups.limits.'.$key));
    }
}
