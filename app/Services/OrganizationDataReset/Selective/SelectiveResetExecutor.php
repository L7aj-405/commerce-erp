<?php

namespace App\Services\OrganizationDataReset\Selective;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationDataReset\OrganizationDataResetAlreadyExecutedException;
use App\Services\OrganizationDataReset\OrganizationDataResetException;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetForeignKeyMap;
use App\Services\OrganizationDataReset\OrganizationDataResetStalePlanException;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;

/**
 * Executes an approved selective plan in ONE transaction:
 *
 *  1. lock the organization row and the selected root rows, re-plan with
 *     the same planner and refuse if the fingerprint changed (stale preview,
 *     concurrent business operation) or the same plan already ran;
 *  2. clear optional links of kept rows (exact planned counts);
 *  3. clear nullable links BETWEEN rows being deleted (breaks reference
 *     cycles such as order ↔ current revision);
 *  4. release active reservations of deleted documents (balance.reserved
 *     lowered under the balance row lock — on-hand never changes);
 *  5. delete child before parent, from the real foreign keys (exact counts);
 *  6. postconditions: nothing planned survives, protected / master data and
 *     stock on-hand untouched, reserved = active reservations; audit event.
 *
 * Any failure rolls everything back; unexpected ones carry a reference.
 */
class SelectiveResetExecutor
{
    /** Typed by the owner (with the organization name and the exact record count). */
    public const PHRASE = 'SUPPRIMER LA SÉLECTION';

    public function __construct(
        private readonly SelectiveResetPlanner $planner,
        private readonly OrganizationDataResetForeignKeyMap $foreignKeys,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{deleted: array<string,int>, unlinked: array<string,int>, released: int, audit_log_id: int} */
    public function execute(Organization $organization, User $actor, SelectiveResetSelection $selection, string $approvedFingerprint): array
    {
        if ((int) $organization->owner_id !== (int) $actor->getKey() && ! $actor->hasPermission($organization, 'system.data_reset')) {
            throw new OrganizationDataResetException('The actor is not authorized to execute an organization data reset.');
        }

        return OrganizationDataResetExecutor::diagnosed($organization, 'selective', fn () => DB::transaction(function () use ($organization, $actor, $selection, $approvedFingerprint) {
            // SHARED lock only: every insert of this organization takes a shared
            // lock on its organizations row for the foreign-key check, so an
            // exclusive lock here deadlocked against a concurrent order
            // confirmation (found by tests/Concurrency/selective_reset_race.php).
            // Two resets are serialized by the selected root rows locked FOR
            // UPDATE in the re-plan + the fingerprint comparison.
            $locked = Organization::query()->whereKey($organization->getKey())->sharedLock()->firstOrFail();
            $plan = $this->planner->plan($locked, $selection, lockRoots: true);
            if (! hash_equals($plan->fingerprint, $approvedFingerprint)) {
                throw new OrganizationDataResetStalePlanException;
            }
            $alreadyDone = AuditLog::query()->where('organization_id', $locked->getKey())
                ->where('event', 'organization.data_reset.selective_completed')
                ->get(['new_values'])
                ->contains(fn (AuditLog $log) => hash_equals((string) ($log->new_values['plan_fingerprint'] ?? ''), $approvedFingerprint));
            if ($alreadyDone) {
                throw new OrganizationDataResetAlreadyExecutedException;
            }
            if (! $plan->isExecutable()) {
                throw new OrganizationDataResetException('The selective reset plan is not executable.');
            }

            $organizationId = (int) $locked->getKey();
            $protectedBefore = $plan->protected;
            $onHandBefore = $this->onHand($organizationId);
            $touched = $this->touchedBalances($organizationId, $plan->release);
            $inconsistentBefore = $this->inconsistentReservations($organizationId, $touched);

            // 2. Optional links of kept rows.
            $unlinked = [];
            foreach ($plan->unlink as $key => $ids) {
                [$table, $column] = explode('.', $key);
                $count = 0;
                foreach (array_chunk($ids, 500) as $chunk) {
                    $count += DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $chunk)->whereNotNull($column)->update([$column => null]);
                }
                if ($count !== count($ids)) {
                    throw new OrganizationDataResetException("Unlink count drifted for [{$key}].");
                }
                $unlinked[$key] = $count;
            }

            // 3. Links between doomed rows.
            foreach ($plan->delete as $table => $ids) {
                foreach ($this->foreignKeys->referencesFrom($table) as $edge) {
                    if (! $edge['nullable'] || ! isset($plan->delete[$edge['parent']])) {
                        continue;
                    }
                    foreach (array_chunk($ids, 500) as $chunk) {
                        DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $chunk)
                            ->whereIn($edge['column'], $plan->delete[$edge['parent']])->update([$edge['column'] => null]);
                    }
                }
            }

            // 4. Release active reservations of deleted documents.
            $this->release($organizationId, $plan->release);

            // 5. Child before parent.
            $deleted = [];
            foreach ($this->order(array_keys($plan->delete)) as $table) {
                $ids = $plan->delete[$table];
                $count = 0;
                foreach (array_chunk($ids, 500) as $chunk) {
                    $count += DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $chunk)->delete();
                }
                if ($count !== count($ids)) {
                    throw new OrganizationDataResetException("Delete count drifted for [{$table}].");
                }
                $deleted[$table] = $count;
            }

            // 6. Postconditions.
            foreach ($plan->delete as $table => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    if (DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $chunk)->exists()) {
                        throw new OrganizationDataResetException("Selective reset postcondition failed for [{$table}].");
                    }
                }
            }
            if ($this->planner->protectedCounts($organizationId) !== $protectedBefore) {
                throw new OrganizationDataResetException('A protected master / configuration record changed during the selective reset.');
            }
            if ($this->onHand($organizationId) !== $onHandBefore) {
                throw new OrganizationDataResetException('Stock on-hand changed during the selective reset.');
            }
            // Never worse than before: no balance may become inconsistent.
            if (array_diff($this->inconsistentReservations($organizationId, $touched), $inconsistentBefore) !== []) {
                throw new OrganizationDataResetException('Selective reset left an inconsistent stock reservation.');
            }

            $audit = $this->audit->record('organization.data_reset.selective_completed', $actor, $locked, auditable: $locked, newValues: [
                'mode' => 'selective',
                'plan_fingerprint' => $plan->fingerprint,
                'selection' => $plan->selection,
                'selected' => $plan->selected,
                'deleted' => $deleted,
                'unlinked' => $unlinked,
                'released_reservations' => count($plan->release),
            ]);

            return ['deleted' => $deleted, 'unlinked' => $unlinked, 'released' => count($plan->release), 'audit_log_id' => (int) $audit->getKey()];
        }));
    }

    /**
     * Child-before-parent order of the tables to delete, from the real
     * foreign keys. Nullable links between doomed rows were cleared (step 3),
     * so only NOT NULL edges constrain the order.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    public function order(array $tables): array
    {
        sort($tables);
        $set = array_fill_keys($tables, true);
        $ordered = [];
        $done = [];
        while (count($ordered) < count($tables)) {
            $progress = false;
            foreach ($tables as $table) {
                if (isset($done[$table])) {
                    continue;
                }
                // `$table` may go once nothing still pending references it.
                $referenced = false;
                foreach ($this->foreignKeys->referencesTo($table) as $edge) {
                    if ($edge['table'] !== $table && isset($set[$edge['table']]) && ! isset($done[$edge['table']])
                        && ! $edge['nullable'] && $edge['on_delete'] !== 'set null') {
                        $referenced = true;
                        break;
                    }
                }
                if (! $referenced) {
                    $ordered[] = $table;
                    $done[$table] = true;
                    $progress = true;
                }
            }
            if (! $progress) {
                throw new OrganizationDataResetException('The selected rows form a foreign-key cycle and cannot be deleted safely.');
            }
        }

        return $ordered;
    }

    /** @param list<int> $reservationIds */
    private function release(int $organizationId, array $reservationIds): void
    {
        if ($reservationIds === []) {
            return;
        }
        $reservations = DB::table('inventory_reservations')->where('organization_id', $organizationId)
            ->whereIn('id', $reservationIds)->where('status', 'active')->lockForUpdate()
            ->get(['id', 'warehouse_id', 'product_variant_id', 'quantity']);
        if ($reservations->count() !== count($reservationIds)) {
            throw new OrganizationDataResetException('A reservation to release is no longer active.');
        }
        foreach ($reservations->groupBy(fn ($r) => $r->warehouse_id.'|'.$r->product_variant_id) as $group) {
            $first = $group->first();
            $balance = DB::table('inventory_balances')->where('organization_id', $organizationId)
                ->where('warehouse_id', $first->warehouse_id)->where('product_variant_id', $first->product_variant_id)
                ->lockForUpdate()->first(['id', 'reserved']);
            $quantity = $group->reduce(fn (string $c, $r) => InventoryQuantity::add($c, (string) $r->quantity), InventoryQuantity::ZERO);
            if (! $balance || InventoryQuantity::compare((string) $balance->reserved, $quantity) < 0) {
                throw new OrganizationDataResetException('A reservation to release exceeds its stock balance.');
            }
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'reserved' => InventoryQuantity::subtract((string) $balance->reserved, $quantity),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string,string> balance id => on_hand */
    private function onHand(int $organizationId): array
    {
        return DB::table('inventory_balances')->where('organization_id', $organizationId)->orderBy('id')
            ->pluck('on_hand', 'id')->map(fn ($v) => InventoryQuantity::normalize((string) $v))->all();
    }

    /** (warehouse, variant) pairs of the reservations this reset releases. @param list<int> $reservationIds @return list<array{0:int,1:int}> */
    private function touchedBalances(int $organizationId, array $reservationIds): array
    {
        return $reservationIds === [] ? [] : DB::table('inventory_reservations')->where('organization_id', $organizationId)
            ->whereIn('id', $reservationIds)->distinct()->get(['warehouse_id', 'product_variant_id'])
            ->map(fn ($r) => [(int) $r->warehouse_id, (int) $r->product_variant_id])->all();
    }

    /**
     * Touched balances whose reserved ≠ sum of active reservations. LOCKING
     * reads: under REPEATABLE READ a plain SELECT would compare the balance
     * we just updated (current) with reservations from the old snapshot and
     * miss a concurrent, committed reservation.
     *
     * @param list<array{0:int,1:int}> $pairs
     * @return list<string>
     */
    private function inconsistentReservations(int $organizationId, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $scope = function ($query) use ($pairs) {
            $query->where(function ($q) use ($pairs) {
                foreach ($pairs as [$warehouse, $variant]) {
                    $q->orWhere(fn ($w) => $w->where('warehouse_id', $warehouse)->where('product_variant_id', $variant));
                }
            });
        };
        $active = DB::table('inventory_reservations')->where('organization_id', $organizationId)->where('status', 'active')
            ->tap($scope)->sharedLock()
            ->get(['warehouse_id', 'product_variant_id', 'quantity'])
            ->groupBy(fn ($r) => $r->warehouse_id.'|'.$r->product_variant_id)
            ->map(fn ($g) => $g->reduce(fn (string $c, $r) => InventoryQuantity::add($c, (string) $r->quantity), InventoryQuantity::ZERO));
        $out = [];
        foreach (DB::table('inventory_balances')->where('organization_id', $organizationId)->tap($scope)->sharedLock()->get(['warehouse_id', 'product_variant_id', 'reserved']) as $balance) {
            $key = $balance->warehouse_id.'|'.$balance->product_variant_id;
            if (InventoryQuantity::compare((string) $balance->reserved, $active->get($key, InventoryQuantity::ZERO)) !== 0) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
