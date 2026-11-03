<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Create-if-missing a row that is about to be locked FOR UPDATE (sequence
 * counters, inventory balances), taking an EXCLUSIVE lock in the same step.
 *
 * The former `insertOrIgnore()` + `lockForUpdate()` shape deadlocks on MySQL
 * under concurrency (measured with tests/Concurrency/*):
 *   - row exists: a duplicate-key INSERT IGNORE takes a SHARED lock; two
 *     sessions holding S both request X for the FOR UPDATE read → deadlock;
 *   - row missing: waiters on the first inserter are all granted S together
 *     when it commits, then deadlock the same way.
 * `INSERT … ON DUPLICATE KEY UPDATE` takes an exclusive lock directly whether
 * the row is new or existing, so sessions simply queue. The caller keeps its
 * own `lockForUpdate()` read afterwards (already held, so it does not wait).
 *
 * `$identity` must be exactly a PRIMARY/UNIQUE key of `$table`.
 */
final class LockedRow
{
    /**
     * @param  array<string, mixed>  $identity  unique-key columns => values
     * @param  array<string, mixed>  $defaults  other columns for a newly created row
     */
    public static function claim(string $table, array $identity, array $defaults = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException("Rows of [{$table}] may only be claimed inside a database transaction.");
        }

        $connection = DB::connection();
        $row = [...$identity, ...$defaults];

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // SQLite serialises writers per database; there are no row locks to order.
            $connection->table($table)->insertOrIgnore($row);

            return;
        }

        $grammar = $connection->getQueryGrammar();
        $columns = implode(', ', array_map(fn (string $column) => $grammar->wrap($column), array_keys($row)));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $key = $grammar->wrap((string) array_key_first($identity));

        $connection->statement(
            "INSERT INTO {$grammar->wrapTable($table)} ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$key} = {$key}",
            array_values($row),
        );
    }
}
