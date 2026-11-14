<?php

namespace App\Services\Sandbox;

use App\Models\Sandbox;
use App\Services\OrganizationDataReset\OrganizationDataResetForeignKeyMap;

/**
 * Phase 10 — policy between the Production organization data reset (full and
 * selective) and Test Mode data.
 *
 * The Production reset must never delete Test rows silently (shared tables
 * such as sales_orders hold both environments) and must never delete a
 * Production parent that Test rows reference (products, warehouses, stores,
 * customers, accounts…). Rather than teaching the Production planners to
 * clean Test data, the reset is BLOCKED whenever the organization's sandbox
 * still holds Test transactional data and the plan touches any table the
 * sandbox owns or references. The user resets Test data first, through the
 * dedicated Sandbox reset; the two destructive operations are never merged.
 */
final class SandboxProductionResetGuard
{
    public const MESSAGE = 'Des données du Mode Test existent encore. Réinitialisez d’abord les données Test avant de réinitialiser les données de Production.';

    /** @var array<string,true>|null */
    private ?array $relevant = null;

    public function __construct(
        private readonly SandboxResetPlanner $planner,
        private readonly OrganizationDataResetForeignKeyMap $foreignKeys,
    ) {}

    /**
     * Number of Test rows that block a Production reset touching `$tables`, or 0.
     *
     * @param  iterable<string>  $tables  tables the Production plan deletes or updates
     */
    public function conflictingTestRows(int $organizationId, iterable $tables): int
    {
        $sandbox = Sandbox::query()->where('organization_id', $organizationId)->first();
        if ($sandbox === null) {
            return 0;
        }
        $relevant = $this->relevantTables();
        $touches = false;
        foreach ($tables as $table) {
            if (isset($relevant[$table])) {
                $touches = true;
                break;
            }
        }

        return $touches ? array_sum($this->planner->counts($sandbox)) : 0;
    }

    /** Tables holding Test rows, plus every table those rows reference (Production parents). */
    private function relevantTables(): array
    {
        if ($this->relevant !== null) {
            return $this->relevant;
        }
        $relevant = [];
        foreach ($this->planner->tables() as $table) {
            $relevant[$table] = true;
            foreach ($this->foreignKeys->referencesFrom($table) as $edge) {
                $relevant[$edge['parent']] = true;
            }
        }
        unset($relevant['sandboxes'], $relevant['organizations'], $relevant['users']);

        return $this->relevant = $relevant;
    }
}
