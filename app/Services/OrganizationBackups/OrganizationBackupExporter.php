<?php

namespace App\Services\OrganizationBackups;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

class OrganizationBackupExporter
{
    public function __construct(private readonly OrganizationBackupSchema $schema) {}

    public function create(Organization $organization, ?User $actor, ?string $targetPath = null): OrganizationBackupArchive
    {
        $directory = storage_path('app/private/organization-backups/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $filename = $this->filename($organization);
        $path = $targetPath ?: $directory.DIRECTORY_SEPARATOR.Str::uuid().'.erpbackup';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new OrganizationBackupException('Impossible de créer l’archive de sauvegarde.');
        }

        $hash = hash_init('sha256');
        $counts = [];
        $tables = $this->schema->tenantTables();
        $sensitive = [];
        $temporaryFiles = [];

        try {
            foreach ($tables as $table) {
                $entry = "data/{$table}.jsonl";
                $tmp = tempnam($directory, 'backup-table-');
                if ($tmp === false) {
                    throw new OrganizationBackupException('Impossible de créer un fichier temporaire de sauvegarde.');
                }
                $temporaryFiles[] = $tmp;
                $handle = fopen($tmp, 'wb');
                if ($handle === false) {
                    throw new OrganizationBackupException('Impossible d’écrire un fichier temporaire de sauvegarde.');
                }

                $count = 0;
                foreach ($this->rows($table, $organization->getKey()) as $row) {
                    $array = $this->sanitizeRow($table, (array) $row, $sensitive);
                    $json = json_encode($array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false) {
                        throw new OrganizationBackupException("Impossible de sérialiser {$table}.");
                    }
                    fwrite($handle, $json."\n");
                    hash_update($hash, $table."\0".$json."\n");
                    $count++;
                }
                fclose($handle);

                $zip->addFile($tmp, $entry);
                $counts[$table] = $count;
            }

            $manifest = [
                'format' => OrganizationBackupManifest::FORMAT,
                'version' => OrganizationBackupManifest::VERSION,
                'created_at' => now()->toIso8601String(),
                'application_version' => config('app.version', 'local'),
                'source_organization_id' => $organization->getKey(),
                'source_organization_name' => $organization->name,
                'created_by_user_id' => $actor?->getKey(),
                'tables' => $tables,
                'counts' => $counts,
                'sensitive_columns' => $sensitive,
                'checksum' => hash_final($hash),
            ];

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            $zip->close();
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }

        return new OrganizationBackupArchive(
            path: $path,
            filename: $filename,
            manifest: $manifest,
            counts: $counts,
            size: filesize($path) ?: 0,
        );
    }

    /** @return iterable<object> */
    private function rows(string $table, int $organizationId): iterable
    {
        $query = DB::table($table)->orderBy($this->schema->columns($table)[0] ?? 'id');
        if ($table === 'organizations') {
            $query->where('id', $organizationId);
        } else {
            $query->where('organization_id', $organizationId);
        }

        foreach ($query->cursor() as $row) {
            yield $row;
        }
    }

    /** @param array<string, mixed> $row @param array<string, list<string>> $sensitive */
    private function sanitizeRow(string $table, array $row, array &$sensitive): array
    {
        foreach (array_keys($row) as $column) {
            if ($this->schema->isSensitiveColumn($column)) {
                $sensitive[$table] ??= [];
                if (! in_array($column, $sensitive[$table], true)) {
                    $sensitive[$table][] = $column;
                }
            }
        }

        return $row;
    }

    private function filename(Organization $organization): string
    {
        $name = Str::slug($organization->name ?: 'organization', '-');

        return Str::upper($name).'_'.now()->format('Y-m-d_His').'.erpbackup';
    }
}
