<?php

namespace App\Services\OrganizationDataReset;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real foreign keys of the database, read from the schema.
 *
 * The reset engine derives its delete order and its "retained reference"
 * blockers from this map instead of a hand-maintained list, so a new
 * migration adding a constraint can never make Preview say "no blocker"
 * while Execution fails on that constraint.
 *
 * Tenant columns are stripped from composite keys: `(organization_id, x)`
 * → `x`; edges whose only column is `organization_id` are ignored.
 *
 * Read once per schema version: one information_schema query on MySQL (the
 * per-table schema builder elsewhere), cached under a key derived from the
 * applied migrations, so a new migration invalidates it by itself. The map
 * is pure schema metadata (no tenant data).
 */
class OrganizationDataResetForeignKeyMap
{
    /** @var array<string, list<array{table:string,column:string,parent:string,parent_column:string,on_delete:string,nullable:bool}>>|null */
    private ?array $outgoing = null;

    /** @var array<string, list<array{table:string,column:string,parent:string,parent_column:string,on_delete:string,nullable:bool}>>|null */
    private ?array $incoming = null;

    /** @return list<array{table:string,column:string,parent:string,parent_column:string,on_delete:string,nullable:bool}> */
    public function referencesFrom(string $table): array
    {
        return $this->load()[$table] ?? [];
    }

    /** @return list<array{table:string,column:string,parent:string,parent_column:string,on_delete:string,nullable:bool}> */
    public function referencesTo(string $parent): array
    {
        if ($this->incoming === null) {
            $this->incoming = [];
            foreach ($this->load() as $edges) {
                foreach ($edges as $edge) {
                    $this->incoming[$edge['parent']][] = $edge;
                }
            }
        }

        return $this->incoming[$parent] ?? [];
    }

    /** True when the edge lets the database itself drop / clear the child. */
    public static function databaseHandles(array $edge): bool
    {
        return in_array($edge['on_delete'], ['cascade', 'set null'], true);
    }

    /** @return array<string, list<array{table:string,column:string,parent:string,parent_column:string,on_delete:string,nullable:bool}>> */
    private function load(): array
    {
        if ($this->outgoing !== null) {
            return $this->outgoing;
        }
        $connection = DB::connection();
        $version = Schema::hasTable('migrations')
            ? DB::table('migrations')->count().':'.DB::table('migrations')->max('migration')
            : 'none';
        $key = 'data-reset:fk-map:v1:'.$connection->getName().':'.$connection->getDatabaseName().':'.md5($version);

        return $this->outgoing = Cache::rememberForever($key, fn () => $connection->getDriverName() === 'mysql'
            ? $this->fromInformationSchema()
            : $this->fromSchemaBuilder());
    }

    /** One query for the whole MySQL schema. */
    private function fromInformationSchema(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT k.CONSTRAINT_NAME AS name, k.TABLE_NAME AS child, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS parent,
                   k.REFERENCED_COLUMN_NAME AS parent_col, r.DELETE_RULE AS on_delete, c.IS_NULLABLE AS nullable
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            JOIN information_schema.COLUMNS c
              ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION
            SQL);

        $constraints = [];
        foreach ($rows as $row) {
            $constraints[$row->child.'|'.$row->name][] = $row;
        }
        $out = [];
        foreach ($constraints as $columns) {
            $columns = array_values(array_filter($columns, fn ($c) => $c->col !== 'organization_id'));
            if (count($columns) !== 1) {
                continue;
            }
            $c = $columns[0];
            $out[$c->child][] = [
                'table' => $c->child, 'column' => $c->col, 'parent' => $c->parent, 'parent_column' => $c->parent_col,
                'on_delete' => strtolower($c->on_delete), 'nullable' => $c->nullable === 'YES',
            ];
        }

        return $out;
    }

    private function fromSchemaBuilder(): array
    {
        $out = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $nullable = collect(Schema::getColumns($name))->mapWithKeys(fn (array $c) => [$c['name'] => (bool) $c['nullable']]);
            foreach (Schema::getForeignKeys($name) as $key) {
                $columns = array_values(array_filter($key['columns'], fn ($c) => $c !== 'organization_id'));
                $parentColumns = array_values(array_filter($key['foreign_columns'], fn ($c) => $c !== 'organization_id'));
                if (count($columns) !== 1 || count($parentColumns) !== 1) {
                    continue;
                }
                $out[$name][] = [
                    'table' => $name, 'column' => $columns[0], 'parent' => $key['foreign_table'], 'parent_column' => $parentColumns[0],
                    'on_delete' => strtolower((string) ($key['on_delete'] ?? 'restrict')), 'nullable' => (bool) ($nullable[$columns[0]] ?? false),
                ];
            }
        }

        return $out;
    }
}
