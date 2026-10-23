<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Sales\McpDryRunRollback;
use Illuminate\Support\Facades\DB;

/**
 * Runs authoritative ERP actions inside a transaction that is always rolled
 * back, so a preview reflects the real business rules without persisting
 * anything. Only for actions whose side effects are database-only.
 */
final class McpDryRun
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $result = null;
        try {
            DB::transaction(function () use ($callback, &$result) {
                $result = $callback();
                throw new McpDryRunRollback;
            });
        } catch (McpDryRunRollback) {
            // Expected: nothing the actions wrote is kept.
        }

        return $result;
    }
}
