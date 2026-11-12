<?php

namespace App\Services\Sandbox;

use Illuminate\Database\Eloquent\Builder;

/**
 * The `sandbox_id` column of the table a model query actually reads from.
 *
 * Global scopes run when the query is compiled, i.e. AFTER callers such as
 * `->from('sandbox_x as a')` or a self-join alias replaced the model's table,
 * so the qualifier must come from the query's current FROM clause rather than
 * from `$model->getTable()` (which would reference a table name that the
 * aliased query no longer exposes).
 */
final class SandboxScopeColumn
{
    public static function for(Builder $builder): string
    {
        $from = $builder->getQuery()->from;

        if (! is_string($from)) {
            // Sub-select / expression source: keep the model table so a mismatch
            // fails loudly (unknown column) instead of silently dropping isolation.
            return $builder->getModel()->qualifyColumn('sandbox_id');
        }

        $qualifier = preg_match('/^.+?\s+as\s+(.+)$/i', $from, $match) ? trim($match[1]) : $from;

        return $qualifier.'.sandbox_id';
    }
}
