<?php

namespace App\Services\Sandbox;

use Illuminate\Database\Connection;

/**
 * Phase 10 — closes the reset ↔ in-flight Test request race centrally.
 *
 * A Test request binds to the sandbox generation captured when SandboxContext
 * resolved it. Before ANY write statement that names a Test transactional
 * table (every table SandboxResetPlanner owns, plus the Test document
 * counters) reaches the database, the fence re-reads the sandbox row and
 * refuses the statement when the generation moved (a reset committed) or the
 * sandbox is no longer active. Raw query builder and Eloquent are both
 * covered because the hook sits on the connection, like SandboxInventoryGuard.
 *
 * Inside a transaction the re-read takes a shared lock on the sandbox row,
 * held until commit. The reset locks the same row FOR UPDATE first thing, so
 * the two serialize: either the Test transaction commits before the reset
 * starts (its rows are then counted and deleted by the reset), or it waits
 * for the reset, sees the new generation and is rolled back. No lock is held
 * outside the request's own write transactions.
 *
 * Production requests (no Test context) are never affected.
 */
final class SandboxGenerationFence
{
    private static bool $checking = false;

    /** @var \WeakMap<Connection, true>|null */
    private static ?\WeakMap $registered = null;

    private static ?string $pattern = null;

    public static function register(Connection $connection): void
    {
        self::$registered ??= new \WeakMap;
        if (isset(self::$registered[$connection])) {
            return;
        }
        self::$registered[$connection] = true;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection) {
            if (self::$checking || ! app()->bound(SandboxContext::class)) {
                return;
            }
            $context = app(SandboxContext::class);
            if (! $context->isSandbox() || ! preg_match('/^\s*(insert|update|delete|replace)\b/i', $query)
                || ! preg_match(self::pattern(), $query)) {
                return;
            }
            self::assertCurrent($connection, $context);
        });
    }

    /** Statement-level check, also usable directly by code that must fail before doing work. */
    public static function assertCurrent(Connection $connection, SandboxContext $context): void
    {
        $sandbox = $context->sandbox();
        if ($sandbox === null) {
            return;
        }
        self::$checking = true;
        try {
            $query = $connection->table('sandboxes')->where('id', $sandbox->getKey())->select(['generation', 'status']);
            if ($connection->transactionLevel() > 0) {
                $query->sharedLock();
            }
            $row = $query->first();
        } finally {
            self::$checking = false;
        }
        if ($row === null || (int) $row->generation !== (int) $context->generation() || $row->status !== 'active') {
            throw new SandboxStaleGenerationException((int) $sandbox->getKey(), (int) $context->generation(), $row === null ? null : (int) $row->generation);
        }
    }

    private static function pattern(): string
    {
        return self::$pattern ??= '/(?<![a-z0-9_])("|`)?('.implode('|', array_map('preg_quote',
            [...app(SandboxResetPlanner::class)->tables(), 'sandbox_document_sequences'])).')\1?(?![a-z0-9_])/i';
    }
}
