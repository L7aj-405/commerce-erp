<?php

namespace App\Services\Sandbox;

use App\Models\Organization;
use App\Models\Sandbox;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationDataReset\OrganizationDataResetForeignKeyMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 9 — wipes one sandbox's Test transactional data and restarts it clean.
 *
 * Independent from the Production organization reset: it only ever deletes
 * the rows SandboxResetPlanner selects (pinned to organization + sandbox),
 * in child → parent order, inside one transaction, with foreign-key checks
 * left ON. Safety nets, all before commit:
 *
 * 1. the sandbox row is locked and must belong to the organization;
 * 2. the confirmation fingerprint must match the locked state (stale preview = refused);
 * 3. preflight: no table outside the plan may reference a Test row (schema
 *    foreign keys, read by the shared OrganizationDataResetForeignKeyMap) —
 *    otherwise the reset refuses instead of letting the database cascade /
 *    null a row the plan does not own;
 * 4. every step must delete exactly the counted rows and leave zero behind,
 *    and each touched table's organization total must drop by exactly that
 *    amount (no Production row can disappear through a cascade);
 * 5. any failure rolls everything back (database RESTRICT keys back this up).
 *
 * On success the generation increments (stale Test sessions stop resolving),
 * `inventory_initialized_at` is cleared (Phase 4 snapshot is retaken on next
 * use), `reset_at` is stamped and `sandbox.reset` is audited. Test document
 * counters are kept (organization-wide, monotonic). Production sequences
 * are never read or written.
 */
final class SandboxResetExecutor
{
    public const PHRASE = 'RESET TEST';

    public function __construct(
        private readonly SandboxResetPlanner $planner,
        private readonly OrganizationDataResetForeignKeyMap $foreignKeys,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{previous_generation:int,generation:int,deleted:array<string,int>,total:int} */
    public function execute(Organization $organization, Sandbox $sandbox, User $actor, string $fingerprint): array
    {
        if ((int) $sandbox->organization_id !== (int) $organization->getKey()) {
            throw new SandboxResetException('Ce bac à sable n’appartient pas à cette organisation.', 'forbidden');
        }
        $files = [];

        $result = DB::transaction(function () use ($organization, $sandbox, $actor, $fingerprint, &$files) {
            $locked = Sandbox::query()->whereKey($sandbox->getKey())
                ->where('organization_id', $organization->getKey())->lockForUpdate()->first();
            if (! $locked) {
                throw new SandboxResetException('Ce bac à sable n’appartient pas à cette organisation.', 'forbidden');
            }
            if ($locked->status !== 'active') {
                throw new SandboxResetException('Ce bac à sable n’est pas actif.', 'blocked');
            }
            $counts = $this->planner->counts($locked);
            if (! hash_equals($this->planner->fingerprint($locked, $counts), $fingerprint)) {
                throw new SandboxResetException('Les données test ont changé depuis l’aperçu. Vérifiez le nouvel aperçu avant de confirmer.', 'stale');
            }
            $this->preflight($locked);
            $totals = $this->organizationTotals($organization);

            foreach ($this->planner->steps() as $step) {
                $files = [...$files, ...$this->deleteStep($locked, $step, $counts[$step['key']])];
            }

            $this->verify($locked, $organization, $counts, $totals);

            $previous = (int) $locked->generation;
            $locked->generation = $previous + 1;
            $locked->inventory_initialized_at = null;
            $locked->reset_at = now();
            $locked->save();

            $deleted = array_filter($counts);
            $this->audit->record('sandbox.reset', $actor, $organization, auditable: $locked,
                oldValues: ['sandbox_id' => (int) $locked->getKey(), 'generation' => $previous],
                newValues: ['sandbox_id' => (int) $locked->getKey(), 'generation' => (int) $locked->generation,
                    'environment' => 'test', 'deleted' => $deleted, 'deleted_total' => array_sum($counts)]);

            return ['previous_generation' => $previous, 'generation' => (int) $locked->generation, 'deleted' => $deleted, 'total' => array_sum($counts)];
        });

        // Stored Test shipping proofs leave the disk only once the rows are gone.
        foreach ($files as [$disk, $path]) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable) {
                // An orphan private file is harmless; the reset itself is committed.
            }
        }
        $sandbox->refresh();

        return $result;
    }

    /** @return list<array{0:string,1:string}> files to remove after commit */
    private function deleteStep(Sandbox $sandbox, array $step, int $expected): array
    {
        $files = [];
        if ($step['table'] === 'sandbox_fulfillment_attachments') {
            $files = $this->planner->query($sandbox, $step)->get(['disk', 'path'])
                ->filter(fn ($row) => $row->disk && $row->path)->map(fn ($row) => [(string) $row->disk, (string) $row->path])->values()->all();
        }
        // Break self references / the order <-> revision cycle on Test rows only.
        if ($step['nullify'] !== []) {
            $this->planner->query($sandbox, $step)->update(array_fill_keys($step['nullify'], null));
        }
        if ($step['parent_nullify'] !== null) {
            [$table, $column] = $step['parent_nullify'];
            DB::table($table)->where('organization_id', $sandbox->organization_id)->where('sandbox_id', $sandbox->getKey())
                ->whereNotNull($column)->update([$column => null]);
        }
        $deleted = $this->planner->query($sandbox, $step)->delete();
        if ($deleted !== $expected) {
            throw new SandboxResetException("Suppression incohérente sur {$step['table']} ({$deleted} au lieu de {$expected}).", 'failed');
        }

        return $files;
    }

    /**
     * Refuse when any row the plan does not delete still points at a Test row:
     * a table outside the plan, or a row of a planned table that belongs to
     * another environment / sandbox (the plan never widens to delete it).
     */
    private function preflight(Sandbox $sandbox): void
    {
        $steps = collect($this->planner->steps())->keyBy('table');
        SandboxInventoryGuard::bypass(function () use ($sandbox, $steps) {
            foreach ($steps as $step) {
                foreach ($this->foreignKeys->referencesTo($step['table']) as $edge) {
                    if ($edge['parent_column'] !== 'id') {
                        continue;
                    }
                    $referencing = DB::table($edge['table'])
                        ->whereIn($edge['table'].'.'.$edge['column'], $this->planner->query($sandbox, $step)->select($step['table'].'.id'));
                    if ($steps->has($edge['table'])) {
                        $child = $steps->get($edge['table']);
                        $referencing->whereNotIn($edge['table'].'.id', $this->planner->query($sandbox, $child)->select($child['table'].'.id'));
                    }
                    $referenced = $referencing->count();
                    if ($referenced > 0) {
                        throw new SandboxResetException("Dépendance inattendue : {$edge['table']}.{$edge['column']} référence des données test ({$step['table']}). Réinitialisation annulée.", 'blocked');
                    }
                }
            }
        });
    }

    /** @return array<string,int> organization-wide row totals of every touched table */
    private function organizationTotals(Organization $organization): array
    {
        $totals = [];
        foreach ($this->planner->tables() as $table) {
            $totals[$table] = DB::table($table)->where('organization_id', $organization->getKey())->count();
        }

        return $totals;
    }

    /**
     * @param  array<string,int>  $counts
     * @param  array<string,int>  $before
     */
    private function verify(Sandbox $sandbox, Organization $organization, array $counts, array $before): void
    {
        $expectedDrop = [];
        foreach ($this->planner->steps() as $step) {
            if ($this->planner->query($sandbox, $step)->exists()) {
                throw new SandboxResetException("Des données test subsistent dans {$step['table']}. Réinitialisation annulée.", 'failed');
            }
            $expectedDrop[$step['table']] = ($expectedDrop[$step['table']] ?? 0) + $counts[$step['key']];
        }
        foreach ($this->organizationTotals($organization) as $table => $after) {
            if ($before[$table] - $after !== $expectedDrop[$table]) {
                throw new SandboxResetException("Écart de lignes sur {$table} : des données hors mode test auraient été touchées. Réinitialisation annulée.", 'failed');
            }
        }
    }
}
