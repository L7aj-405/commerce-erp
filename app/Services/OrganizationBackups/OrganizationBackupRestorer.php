<?php

namespace App\Services\OrganizationBackups;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use JsonException;
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

        // Fail before creating a safety snapshot or replacing any rows when
        // protected state would be orphaned by the historical business data.
        $preflightZip = $this->validator->open($path);
        try {
            $this->assertProtectedStateCompatible($preflightZip, $manifest, $organization);
        } finally {
            $preflightZip->close();
        }

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
        $tables = $manifest['restore_tables'] ?? $manifest['tables'];
        if (! is_array($tables)) {
            throw new OrganizationBackupException('Manifeste de sauvegarde malformé.');
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new OrganizationBackupException("Le pilote de base de données {$driver} n’est pas pris en charge pour la restauration.");
        }

        $startingTransactionLevel = $connection->transactionLevel();
        $foreignKeyState = null;
        $connection->beginTransaction();
        try {
            $this->lockProtectedState($organization);
            // Re-run under the restore transaction to close the gap between
            // the initial non-mutating preflight and destructive replacement.
            $this->assertProtectedStateCompatible($zip, $manifest, $organization);
            $foreignKeyState = $this->deferForeignKeyChecks($connection, $driver);
            if (in_array('warehouses', $tables, true)) {
                $this->clearMemberDefaultWarehousesMissingFrom($zip, $organization);
            }

            foreach ($tables as $table) {
                if ($table !== 'organizations' && $this->schema->hasOrganizationColumn($table)) {
                    $this->deleteCurrentRows($zip, (string) $table, $organization, $driver);
                }
            }

            foreach ($tables as $table) {
                $this->restoreTable(
                    $zip,
                    (string) $table,
                    $organization,
                    preserveExisting: $driver === 'sqlite' && in_array($table, ['stores', 'warehouses'], true),
                );
            }

            if ($driver === 'sqlite') {
                $this->assertSqliteForeignKeyIntegrity($connection);
            }
            $this->restoreForeignKeyState($connection, $foreignKeyState);
            $foreignKeyState = null;
            $this->assertProtectedRelationsIntact($organization);
            $connection->commit();
        } catch (\Throwable $exception) {
            // Roll back our transaction/savepoint before any cleanup statement.
            // Cleanup must never prevent rollback or replace the original error.
            try {
                if ($connection->transactionLevel() > $startingTransactionLevel) {
                    $connection->rollBack($startingTransactionLevel);
                }
            } catch (\Throwable $rollbackException) {
                $this->reportCleanupFailure($rollbackException);
            } finally {
                if ($foreignKeyState !== null) {
                    try {
                        $this->restoreForeignKeyState($connection, $foreignKeyState);
                    } catch (\Throwable $cleanupException) {
                        $this->reportCleanupFailure($cleanupException);
                    }
                }
            }

            throw $exception;
        }
    }

    /** @return array{driver:string, enabled:bool} */
    private function deferForeignKeyChecks(Connection $connection, string $driver): array
    {
        if ($driver === 'sqlite') {
            $row = $connection->selectOne('PRAGMA defer_foreign_keys');
            $enabled = (bool) ($row->defer_foreign_keys ?? false);
            $connection->statement('PRAGMA defer_foreign_keys = ON');

            return ['driver' => $driver, 'enabled' => $enabled];
        }

        $row = $connection->selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled');
        $enabled = (bool) ($row->enabled ?? true);
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        return ['driver' => $driver, 'enabled' => $enabled];
    }

    /** @param array{driver:string, enabled:bool} $state */
    private function restoreForeignKeyState(Connection $connection, array $state): void
    {
        if ($state['driver'] === 'sqlite') {
            $connection->statement('PRAGMA defer_foreign_keys = '.($state['enabled'] ? 'ON' : 'OFF'));

            return;
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS='.($state['enabled'] ? '1' : '0'));
    }

    private function assertSqliteForeignKeyIntegrity(Connection $connection): void
    {
        if ($connection->select('PRAGMA foreign_key_check') !== []) {
            throw new OrganizationBackupException('La restauration créerait une référence de base de données invalide. Aucune donnée n’a été restaurée.');
        }
    }

    private function reportCleanupFailure(\Throwable $exception): void
    {
        try {
            report($exception);
        } catch (\Throwable) {
            // Preserve the original restore failure even if reporting fails.
        }
    }

    private function deleteCurrentRows(ZipArchive $zip, string $table, Organization $organization, string $driver): void
    {
        $query = DB::table($table)->where('organization_id', $organization->getKey());

        // SQLite cannot disable foreign keys inside RefreshDatabase's outer
        // transaction. Keep protected parent rows in place so ON DELETE
        // CASCADE / SET NULL actions cannot mutate memberships, audit rows or
        // active-store pointers; their business fields are updated below.
        if ($driver === 'sqlite' && in_array($table, ['stores', 'warehouses'], true)) {
            $archiveIds = $this->archiveIds($zip, $table, 'id');
            if ($archiveIds !== []) {
                $query->whereNotIn('id', $archiveIds);
            }
        }

        $query->delete();
    }

    /** @param array<string, mixed> $manifest */
    private function assertProtectedStateCompatible(ZipArchive $zip, array $manifest, Organization $organization): void
    {
        $tables = $manifest['restore_tables'] ?? $manifest['tables'] ?? [];
        if (! is_array($tables)) {
            throw new OrganizationBackupException('Manifeste de sauvegarde malformé.');
        }

        if (in_array('stores', $tables, true)) {
            $archiveStoreIds = $this->archiveIds($zip, 'stores', 'id');
            $currentStoreIds = DB::table('stores')->where('organization_id', $organization->getKey())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $requiredStoreIds = collect()
                ->merge(DB::table('store_memberships')->where('organization_id', $organization->getKey())->pluck('store_id'))
                ->merge(DB::table('audit_logs')->where('organization_id', $organization->getKey())->whereNotNull('store_id')->pluck('store_id'))
                ->merge(DB::table('woocommerce_integrations')->where('organization_id', $organization->getKey())->whereNotNull('default_store_id')->pluck('default_store_id'))
                ->merge($currentStoreIds === [] ? [] : DB::table('users')->whereIn('active_store_id', $currentStoreIds)->pluck('active_store_id'))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            $this->assertIdsRemain($requiredStoreIds, $archiveStoreIds, 'La sauvegarde supprimerait un magasin encore référencé par un état protégé.');
        }

        if (in_array('warehouses', $tables, true)) {
            $archiveWarehouseIds = $this->archiveIds($zip, 'warehouses', 'id');
            $requiredWarehouseIds = DB::table('woocommerce_integrations')
                ->where('organization_id', $organization->getKey())
                ->whereNotNull('default_warehouse_id')
                ->pluck('default_warehouse_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $this->assertIdsRemain($requiredWarehouseIds, $archiveWarehouseIds, 'La sauvegarde supprimerait un entrepôt encore référencé par une intégration protégée.');
        }

        $preservedIntegrationIds = DB::table('woocommerce_integrations')
            ->where('organization_id', $organization->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (['product_channel_identifiers', 'woocommerce_sync_runs', 'woocommerce_category_mappings', 'woocommerce_stock_tasks'] as $table) {
            if (! in_array($table, $tables, true)) {
                continue;
            }
            $archiveIntegrationIds = $this->archiveIds($zip, $table, 'woocommerce_integration_id', nullable: true);
            $this->assertIdsRemain(
                $archiveIntegrationIds,
                $preservedIntegrationIds,
                'La sauvegarde contient un état WooCommerce dont l’intégration parente n’existe plus.',
            );
        }
    }

    /** @param list<int> $required @param list<int> $available */
    private function assertIdsRemain(array $required, array $available, string $message): void
    {
        if (array_diff($required, $available) !== []) {
            throw new OrganizationBackupException($message);
        }
    }

    /** @return list<int> */
    private function archiveIds(ZipArchive $zip, string $table, string $column, bool $nullable = false): array
    {
        $stream = $zip->getStream("data/{$table}.jsonl");
        if (! is_resource($stream)) {
            throw new OrganizationBackupException('La sauvegarde est incomplète.');
        }

        $ids = [];
        $lineLimit = max(1, (int) config('organization-backups.limits.jsonl_line_bytes'));
        try {
            while (($line = fgets($stream, $lineLimit + 2)) !== false) {
                try {
                    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new OrganizationBackupException('Jeu de données de sauvegarde malformé.');
                }
                $value = is_array($row) ? ($row[$column] ?? null) : null;
                if ($value === null && $nullable) {
                    continue;
                }
                if (! is_numeric($value) || (int) $value <= 0) {
                    throw new OrganizationBackupException('Jeu de données de sauvegarde malformé.');
                }
                $ids[(int) $value] = true;
            }
        } finally {
            fclose($stream);
        }

        return array_keys($ids);
    }

    private function lockProtectedState(Organization $organization): void
    {
        $organizationId = (int) $organization->getKey();
        $storeIds = DB::table('stores')->where('organization_id', $organizationId)->pluck('id');

        DB::table('store_memberships')->where('organization_id', $organization->getKey())->lockForUpdate()->get(['id']);
        DB::table('organization_memberships')->where('organization_id', $organizationId)->lockForUpdate()->get(['id']);
        DB::table('audit_logs')->where('organization_id', $organizationId)->lockForUpdate()->get(['id']);
        DB::table('users')
            ->where(function ($query) use ($organizationId, $storeIds) {
                $query->where('active_organization_id', $organizationId);
                if ($storeIds->isNotEmpty()) {
                    $query->orWhereIn('active_store_id', $storeIds);
                }
            })
            ->lockForUpdate()
            ->get(['id']);
        DB::table('woocommerce_integrations')->where('organization_id', $organizationId)->lockForUpdate()->get(['id']);
    }

    /**
     * Memberships are protected (never restored), but their POS default
     * warehouse is only a preference. If the restored data set no longer
     * contains that warehouse, drop the preference instead of failing the
     * restore or leaving a dangling reference. Runs before current warehouses
     * are deleted: the FK is RESTRICT, which SQLite enforces immediately.
     */
    private function clearMemberDefaultWarehousesMissingFrom(ZipArchive $zip, Organization $organization): void
    {
        DB::table('organization_memberships')
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('default_warehouse_id')
            ->whereNotIn('default_warehouse_id', $this->archiveIds($zip, 'warehouses', 'id'))
            ->update(['default_warehouse_id' => null]);
    }

    private function assertProtectedRelationsIntact(Organization $organization): void
    {
        $organizationId = (int) $organization->getKey();

        if ($this->missingTenantReference('store_memberships', 'store_id', 'stores', $organizationId)
            || $this->missingTenantReference('audit_logs', 'store_id', 'stores', $organizationId, true)
            || $this->missingTenantReference('woocommerce_integrations', 'default_store_id', 'stores', $organizationId, true)
            || $this->missingTenantReference('woocommerce_integrations', 'default_warehouse_id', 'warehouses', $organizationId, true)
            || $this->missingTenantReference('woocommerce_sync_runs', 'woocommerce_integration_id', 'woocommerce_integrations', $organizationId)
            || $this->missingTenantReference('woocommerce_category_mappings', 'woocommerce_integration_id', 'woocommerce_integrations', $organizationId)
            || $this->missingTenantReference('woocommerce_category_mappings', 'category_id', 'categories', $organizationId)
            || $this->missingTenantReference('product_channel_identifiers', 'product_id', 'products', $organizationId)
            || $this->missingTenantReference('product_channel_identifiers', 'product_variant_id', 'product_variants', $organizationId, true)
            || $this->missingTenantReference('product_channel_identifiers', 'woocommerce_integration_id', 'woocommerce_integrations', $organizationId, true)
            || $this->missingTenantReference('woocommerce_stock_tasks', 'store_id', 'stores', $organizationId, true)
            || $this->missingTenantReference('woocommerce_stock_tasks', 'product_id', 'products', $organizationId)
            || $this->missingTenantReference('woocommerce_stock_tasks', 'product_variant_id', 'product_variants', $organizationId)
            || $this->missingTenantReference('woocommerce_stock_tasks', 'warehouse_id', 'warehouses', $organizationId, true)
            || $this->missingTenantReference('woocommerce_stock_tasks', 'woocommerce_integration_id', 'woocommerce_integrations', $organizationId, true)
            || $this->invalidActiveStoreExists($organizationId)) {
            throw new OrganizationBackupException('La restauration créerait une référence protégée invalide. Aucune donnée n’a été restaurée.');
        }
    }

    private function missingTenantReference(string $childTable, string $foreignKey, string $parentTable, int $organizationId, bool $nullable = false): bool
    {
        if (! $this->schema->isKnownTenantTable($childTable) || ! $this->schema->isKnownTenantTable($parentTable)) {
            return false;
        }

        return DB::table("{$childTable} as child")
            ->leftJoin("{$parentTable} as parent", function (JoinClause $join) use ($foreignKey) {
                $join->on("child.{$foreignKey}", '=', 'parent.id')
                    ->on('child.organization_id', '=', 'parent.organization_id');
            })
            ->where('child.organization_id', $organizationId)
            ->when($nullable, fn ($query) => $query->whereNotNull("child.{$foreignKey}"))
            ->whereNull('parent.id')
            ->exists();
    }

    private function invalidActiveStoreExists(int $organizationId): bool
    {
        return DB::table('users')
            ->leftJoin('stores', 'users.active_store_id', '=', 'stores.id')
            ->where('users.active_organization_id', $organizationId)
            ->whereNotNull('users.active_store_id')
            ->where(function ($query) use ($organizationId) {
                $query->whereNull('stores.id')->orWhere('stores.organization_id', '!=', $organizationId);
            })
            ->exists();
    }

    private function restoreTable(ZipArchive $zip, string $table, Organization $organization, bool $preserveExisting = false): void
    {
        $stream = $zip->getStream("data/{$table}.jsonl");
        if (! is_resource($stream)) {
            throw new OrganizationBackupException('La sauvegarde est incomplète.');
        }

        $chunk = [];
        $rows = 0;
        $lineLimit = max(1, (int) config('organization-backups.limits.jsonl_line_bytes'));
        $rowLimit = max(1, (int) config('organization-backups.limits.rows_per_table'));
        while (($line = fgets($stream, $lineLimit + 2)) !== false) {
            if (! str_ends_with($line, "\n") && ! feof($stream)) {
                throw new OrganizationBackupException('Une ligne de sauvegarde dépasse la taille autorisée.');
            }
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($row) || ! $this->schema->hasOnlyKnownColumns($table, array_keys($row))) {
                throw new OrganizationBackupException('Jeu de données de sauvegarde malformé.');
            }
            $this->assertTenantRow($table, $row, $organization);
            $rows++;
            if ($rows > $rowLimit) {
                throw new OrganizationBackupException('La sauvegarde contient trop de lignes pour une table.');
            }

            if ($table === 'organizations') {
                $this->restoreOrganization($row, $organization);
                continue;
            }

            if ($preserveExisting) {
                DB::table($table)->updateOrInsert(['id' => $row['id']], $row);
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
        // Authentication, membership, RBAC, audit and integration secrets are
        // deliberately absent from the restore allowlist. The organization row
        // itself may restore business/document settings only. Platform-owned
        // ownership, lifecycle status and timestamps retain their current values.
        $businessValues = array_intersect_key($row, array_flip(['name', 'settings']));
        DB::table('organizations')->where('id', $organization->getKey())->update($businessValues);
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
