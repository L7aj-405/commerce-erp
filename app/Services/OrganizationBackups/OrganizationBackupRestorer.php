<?php

namespace App\Services\OrganizationBackups;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class OrganizationBackupRestorer
{
    public function __construct(
        private readonly OrganizationBackupValidator $validator,
        private readonly OrganizationBackupExporter $exporter,
        private readonly OrganizationBackupSchema $schema,
    ) {}

    /** @return array{pre_restore_path:string, manifest:array<string, mixed>} */
    public function restore(string $path, Organization $organization, User $actor): array
    {
        $validated = $this->validator->validatePath($path, $organization);
        $manifest = $validated['manifest'];

        $preRestorePath = $this->preRestorePath($organization);
        $this->exporter->create($organization, $actor, $preRestorePath);

        $zip = $this->validator->open($path);
        try {
            $this->restoreZip($zip, $manifest, $organization);
        } finally {
            $zip->close();
        }

        return ['pre_restore_path' => $preRestorePath, 'manifest' => $manifest];
    }

    /** @param array<string, mixed> $manifest */
    private function restoreZip(ZipArchive $zip, array $manifest, Organization $organization): void
    {
        $tables = $manifest['tables'];
        if (! is_array($tables)) {
            throw new OrganizationBackupException('Manifeste de sauvegarde malformé.');
        }

        DB::beginTransaction();
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($tables as $table) {
                if ($table !== 'organizations' && $this->schema->hasOrganizationColumn($table)) {
                    DB::table($table)->where('organization_id', $organization->getKey())->delete();
                }
            }

            foreach ($tables as $table) {
                $this->restoreTable($zip, (string) $table, $organization);
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::commit();
        } catch (\Throwable $exception) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::rollBack();

            throw $exception;
        }
    }

    private function restoreTable(ZipArchive $zip, string $table, Organization $organization): void
    {
        $stream = $zip->getStream("data/{$table}.jsonl");
        if (! is_resource($stream)) {
            throw new OrganizationBackupException('La sauvegarde est incomplète.');
        }

        $chunk = [];
        while (($line = fgets($stream)) !== false) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($row)) {
                throw new OrganizationBackupException('Jeu de données de sauvegarde malformé.');
            }
            $this->assertTenantRow($table, $row, $organization);

            if ($table === 'organizations') {
                $this->restoreOrganization($row, $organization);
                continue;
            }

            $chunk[] = $row;
            if (count($chunk) >= 500) {
                DB::table($table)->insert($chunk);
                $chunk = [];
            }
        }
        fclose($stream);

        if ($chunk !== []) {
            DB::table($table)->insert($chunk);
        }
    }

    /** @param array<string, mixed> $row */
    private function assertTenantRow(string $table, array $row, Organization $organization): void
    {
        if ($table === 'organizations') {
            if ((int) ($row['id'] ?? 0) !== (int) $organization->getKey()) {
                throw new OrganizationBackupException('La sauvegarde ne correspond pas à cette organisation.');
            }

            return;
        }

        if ((int) ($row['organization_id'] ?? 0) !== (int) $organization->getKey()) {
            throw new OrganizationBackupException('La sauvegarde contient des données hors organisation.');
        }
    }

    /** @param array<string, mixed> $row */
    private function restoreOrganization(array $row, Organization $organization): void
    {
        $id = $row['id'] ?? null;
        unset($row['id']);
        DB::table('organizations')->where('id', $id)->update($row);
    }

    private function preRestorePath(Organization $organization): string
    {
        $directory = storage_path('app/private/organization-backups/pre-restore/'.$organization->getKey());
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_pre_restore.erpbackup';
    }
}
