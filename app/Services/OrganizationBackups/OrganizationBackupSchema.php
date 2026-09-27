<?php

namespace App\Services\OrganizationBackups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrganizationBackupSchema
{
    /** @var list<string> */
    private const EXCLUDED_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'jobs',
        'job_batches',
        'migrations',
        'password_reset_tokens',
        'sessions',
    ];

    /** @return list<string> */
    public function tenantTables(): array
    {
        $tables = collect($this->allTables())
            ->filter(fn (string $table) => ! in_array($table, self::EXCLUDED_TABLES, true))
            ->filter(fn (string $table) => Schema::hasColumn($table, 'organization_id'))
            ->sort()
            ->values()
            ->all();

        return array_values(array_unique(['organizations', ...$tables]));
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    public function hasOrganizationColumn(string $table): bool
    {
        return Schema::hasColumn($table, 'organization_id');
    }

    public function isSensitiveColumn(string $column): bool
    {
        $column = strtolower($column);

        return str_contains($column, 'password')
            || str_contains($column, 'secret')
            || str_contains($column, 'token')
            || str_contains($column, 'api_key')
            || str_contains($column, 'consumer_key')
            || str_contains($column, 'consumer_secret')
            || str_contains($column, 'access_key')
            || str_contains($column, 'private_key');
    }

    /** @return list<string> */
    private function allTables(): array
    {
        $database = DB::getDatabaseName();

        return collect(DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))
            ->map(function (object $row) use ($database): string {
                $key = "Tables_in_{$database}";

                return (string) ($row->{$key} ?? array_values((array) $row)[0]);
            })
            ->values()
            ->all();
    }
}
