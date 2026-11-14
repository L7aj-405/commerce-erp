<?php

namespace App\Services\OrganizationDataReset\Selective;

use App\Models\CreditNote;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderProcurement;
use App\Services\OrganizationDataReset\OrganizationDataResetForeignKeyMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use App\Services\Sandbox\SandboxProductionResetGuard;
use Illuminate\Support\Facades\Schema;

/**
 * Row-level dependency planner of the selective reset — the SAME code for
 * the preview and, inside the execution transaction, for the re-check.
 *
 * Starting from the selected root rows it follows every real foreign key
 * that points at a row to delete, and classifies each referencing row:
 *
 *   delete  — it belongs to a selected category or is owned by a deleted
 *             document (lines, revisions, shipments, allocations…);
 *   unlink  — an optional link from a row that is kept (ON DELETE SET NULL,
 *             or a nullable traceability link listed in UNLINKABLE): the
 *             row stays, only the link is cleared;
 *   block   — anything else (a kept invoice pointing at a deleted order, a
 *             Finance obligation pointing at a deleted procurement…). The
 *             plan is not executable; the scope is never widened silently.
 *
 * Master data is never reached: it is only ever the parent of a reference.
 * Stock quantities and the stock ledger are kept; only ACTIVE reservations
 * of deleted documents are released (balance.reserved lowered) and removed.
 */
class SelectiveResetPlanner
{
    /** Hard ceiling of rows one run may delete (narrow the selection above it). */
    public const MAX_ROWS = 20000;

    /**
     * Nullable traceability links that may be cleared on a kept row (the row
     * remains fully valid without them). Anything not here and not ON DELETE
     * SET NULL blocks.
     */
    private const UNLINKABLE = [
        'sales_order_procurements.source_quotation_id' => true,
        'sales_order_procurements.source_quotation_line_id' => true,
        'sales_order_procurements.source_supplier_request_id' => true,
        'sales_order_procurements.transfer_request_id' => true,
        'procurement_receipts.purchase_order_line_id' => true,
    ];

    /** Polymorphic (logical) children removed with their document. */
    private const STAMPABLE = [
        'invoices' => Invoice::class,
        'credit_notes' => CreditNote::class,
        'delivery_notes' => DeliveryNote::class,
        'quotations' => Quotation::class,
    ];

    private const ATTENTION_SUBJECTS = ['quotations' => 'quotation', 'invoices' => 'invoice', 'sales_orders' => 'sales_order'];

    public function __construct(
        private readonly SelectiveResetCategoryRegistry $registry,
        private readonly OrganizationDataResetForeignKeyMap $foreignKeys,
    ) {}

    public function plan(Organization $organization, SelectiveResetSelection $selection, bool $lockRoots = false): SelectiveResetPlan
    {
        $organizationId = (int) $organization->getKey();
        $rootTables = $this->registry->roots();
        $selectedCategories = array_fill_keys($selection->categories, true);
        // An owned row follows a deleted parent only when the parent and the
        // row share a SELECTED category: a payment allocation reached from a
        // deleted sales order is NOT deleted unless its payment is (it blocks).
        $ownedThrough = function (string $child, string $parent) use ($selectedCategories): bool {
            foreach (array_intersect($this->registry->categoriesOf($child), $this->registry->categoriesOf($parent)) as $category) {
                if (isset($selectedCategories[$category])) {
                    return true;
                }
            }

            return false;
        };

        // Identifier sets: keyed by the id's string form, VALUE = the id with
        // its schema type (int for integer keys, exact string for ULID /
        // char keys). Ids are always read back with array_values(), never
        // array_keys() nor (int) casts — see ForeignKeyMap::typedIds().
        $doomed = [];    // table => [key => id]
        $unlink = [];    // "table.column" => [key => id]
        $blocked = [];   // "child.column>parent" => [key => id]
        $queue = [];
        $add = function (string $table, array $ids) use (&$doomed, &$queue): void {
            $new = [];
            foreach ($this->foreignKeys->typedIds($table, $ids) as $id) {
                if (! isset($doomed[$table][(string) $id])) {
                    $doomed[$table][(string) $id] = $id;
                    $new[] = $id;
                }
            }
            if ($new !== []) {
                $queue[] = [$table, $new];
            }
        };

        // 1. Explicit selection (category × date range × records / filters).
        $selected = [];
        foreach ($selection->categories as $category) {
            foreach ($this->registry->find($category)['roots'] as $root) {
                if (! Schema::hasTable($root['table'])) {
                    continue;
                }
                $query = $this->rootQuery($organization, $root, $selection);
                if ($lockRoots) {
                    $query->lockForUpdate();
                }
                $ids = $this->foreignKeys->typedIds($root['table'], $query->pluck($root['table'].'.id'));
                $selected[$root['table']] = count($ids);
                $add($root['table'], $ids);
            }
        }

        // 2. Dependency closure over the real foreign keys + logical children.
        $logicalDone = [];
        while ($queue !== [] || ($pending = $this->logicalChildren($organizationId, $doomed, $logicalDone)) !== []) {
            if ($queue === []) {
                foreach ($pending as $table => $ids) {
                    $add($table, $ids);
                }

                continue;
            }
            [$table, $ids] = array_shift($queue);
            foreach ($this->foreignKeys->referencesTo($table) as $edge) {
                $child = $edge['table'];
                if (! Schema::hasColumn($child, 'id')) {
                    continue;
                }
                $scoped = Schema::hasColumn($child, 'organization_id');
                $rows = [];
                foreach (array_chunk($ids, 500) as $chunk) {
                    $query = DB::table($child)->whereIn($edge['column'], $chunk);
                    if ($scoped) {
                        $query->where('organization_id', $organizationId);
                    }
                    // `$chunk` holds the PARENT's typed ids: bound with the type of
                    // the referenced key (a char(26) ULID stays a string).
                    foreach ($this->foreignKeys->typedIds($child, $query->pluck('id')) as $id) {
                        if (! isset($doomed[$child][(string) $id])) {
                            $rows[] = $id;
                        }
                    }
                }
                if ($rows === []) {
                    continue;
                }
                // Root documents are only ever deleted when explicitly selected
                // (category × date × records); reached through a dependency they
                // block instead — the scope is never widened silently.
                if ($scoped && ! isset($rootTables[$child]) && $ownedThrough($child, $table)) {
                    $add($child, $rows);
                } elseif ($edge['nullable'] && ($edge['on_delete'] === 'set null' || isset(self::UNLINKABLE[$child.'.'.$edge['column']]))) {
                    foreach ($rows as $id) {
                        $unlink[$child.'.'.$edge['column']][(string) $id] = $id;
                    }
                } else {
                    foreach ($rows as $id) {
                        $blocked[$child.'.'.$edge['column'].'>'.$table][(string) $id] = $id;
                    }
                }
            }
        }

        // A row that ends up deleted (reached through another path) is neither
        // "unlinked" nor a blocker.
        foreach ($unlink as $key => $ids) {
            $table = explode('.', $key)[0];
            $unlink[$key] = array_diff_key($ids, $doomed[$table] ?? []);
            if ($unlink[$key] === []) {
                unset($unlink[$key]);
            }
        }
        foreach ($blocked as $key => $ids) {
            $table = explode('.', $key)[0];
            $blocked[$key] = array_diff_key($ids, $doomed[$table] ?? []);
            if ($blocked[$key] === []) {
                unset($blocked[$key]);
            }
        }

        $blockers = $this->blockers($organizationId, $blocked, $selectedCategories);
        // Phase 10: Test Mode data is never deleted by, nor left dangling by, a Production cleanup.
        $testRows = app(SandboxProductionResetGuard::class)->conflictingTestRows($organizationId,
            [...array_keys($doomed), ...array_map(fn (string $key) => explode('.', $key)[0], array_keys($unlink))]);
        if ($testRows > 0) {
            array_unshift($blockers, ['code' => 'sandbox_test_data', 'count' => $testRows, 'message' => SandboxProductionResetGuard::MESSAGE]);
        }
        $release = array_values($doomed['inventory_reservations'] ?? []);
        $total = array_sum(array_map('count', $doomed));
        if ($total > self::MAX_ROWS) {
            $blockers[] = ['code' => 'too_large', 'count' => $total, 'limit' => self::MAX_ROWS];
        }
        $receiptConflicts = $release === [] ? 0 : DB::table('procurement_receipts')->where('organization_id', $organizationId)
            ->whereIn('inventory_reservation_id', $release)
            ->whereNotIn('id', array_values($doomed['procurement_receipts'] ?? []) ?: [0])->count();
        if ($receiptConflicts > 0) {
            $blockers[] = ['code' => 'receipt_reservation', 'count' => $receiptConflicts, 'table' => 'procurement_receipts'];
        }

        $delete = [];
        foreach ($doomed as $table => $ids) {
            $delete[$table] = $this->sortedIds($table, $ids);
        }
        ksort($delete);
        $unlinkLists = [];
        foreach ($unlink as $key => $ids) {
            $unlinkLists[$key] = $this->sortedIds(explode('.', $key)[0], $ids);
        }
        ksort($unlinkLists);
        sort($release, SORT_NUMERIC);

        $breakdown = $this->breakdown($selection, $delete, $unlinkLists);
        $samples = $this->samples($delete);
        $protected = $this->protectedCounts($organizationId);

        $fingerprint = hash('sha256', json_encode([
            'organization_id' => $organizationId,
            'selection' => $selection->toArray(),
            'delete' => $delete,
            'unlink' => $unlinkLists,
            'release' => $release,
            'blockers' => $blockers,
            'state' => $this->stateHash($organizationId, $delete, $unlinkLists),
        ], JSON_THROW_ON_ERROR));

        return new SelectiveResetPlan(
            $organizationId, $selection->toArray(), $delete, $unlinkLists, $release, $selected,
            $blockers, $breakdown, $samples, $protected, $fingerprint, CarbonImmutable::now(),
        );
    }

    /** Root rows of one root table matching the date range and record / filter selection. */
    public function rootQuery(Organization $organization, array $root, SelectiveResetSelection $selection): Builder
    {
        $table = $root['table'];
        $query = DB::table($table)->where($table.'.organization_id', (int) $organization->getKey());

        if (isset($selection->records[$table])) {
            return $query->whereIn($table.'.id', $selection->records[$table]);
        }

        $this->applyDateRange($query, $organization, $root['date'], $selection->dateFrom, $selection->dateTo);
        $this->applyFilters($query, $root, $selection->filters[$table] ?? []);

        return $query;
    }

    /** @param array{search?:string,status?:string,warehouse_id?:int} $filters */
    public function applyFilters(Builder $query, array $root, array $filters): void
    {
        $table = $root['table'];
        if (isset($filters['status']) && $root['status'] !== null) {
            $query->where($table.'.'.$root['status'], $filters['status']);
        }
        if (isset($filters['warehouse_id']) && $root['warehouses'] !== []) {
            $query->where(function (Builder $q) use ($table, $root, $filters) {
                foreach ($root['warehouses'] as $column) {
                    $q->orWhere($table.'.'.$column, $filters['warehouse_id']);
                }
            });
        }
        if (isset($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['search']).'%';
            $query->where(function (Builder $q) use ($table, $root, $term) {
                $q->where($table.'.'.$root['number'], 'like', $term);
                if ($root['party'] !== null) {
                    [$partyTable, $column, $name] = $root['party'];
                    $q->orWhereIn($table.'.'.$column, DB::table($partyTable)->select('id')->where($name, 'like', $term));
                }
            });
        }
    }

    /**
     * Business-date range. DATE columns compare as dates; timestamps (stored
     * in UTC) are compared with the organization's local day boundaries.
     */
    public function applyDateRange(Builder $query, Organization $organization, string $expression, ?string $from, ?string $to): void
    {
        if ($from === null && $to === null) {
            return;
        }
        $isTimestamp = str_contains($expression, '_at');
        if (! $isTimestamp) {
            if ($from !== null) {
                $query->whereRaw("{$expression} >= ?", [$from]);
            }
            if ($to !== null) {
                $query->whereRaw("{$expression} <= ?", [$to]);
            }

            return;
        }
        $zone = $organization->businessTimezone();
        if ($from !== null) {
            $query->whereRaw("{$expression} >= ?", [CarbonImmutable::parse($from, $zone)->startOfDay()->utc()->format('Y-m-d H:i:s')]);
        }
        if ($to !== null) {
            $query->whereRaw("{$expression} <= ?", [CarbonImmutable::parse($to, $zone)->endOfDay()->utc()->format('Y-m-d H:i:s')]);
        }
    }

    /**
     * Polymorphic children of deleted rows (stamps, document attention) and
     * the ACTIVE reservations held by deleted allocations / procurements.
     * Each table is evaluated once its parents are final.
     *
     * @param array<string, array<int,bool>> $doomed
     * @param array<string, bool> $done
     * @return array<string, list<int|string>>
     */
    private function logicalChildren(int $organizationId, array $doomed, array &$done): array
    {
        $out = [];
        $signature = fn (array $tables) => md5(json_encode(array_map(fn ($t) => count($doomed[$t] ?? []), $tables)));

        $stampTables = array_keys(self::STAMPABLE);
        $key = 'stamps:'.$signature($stampTables);
        if (! isset($done[$key]) && Schema::hasTable('document_stamp_appositions')) {
            $done[$key] = true;
            foreach (self::STAMPABLE as $table => $class) {
                if (($ids = array_values($doomed[$table] ?? [])) !== []) {
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $found = DB::table('document_stamp_appositions')->where('organization_id', $organizationId)
                            ->where('stampable_type', $class)->whereIn('stampable_id', $chunk)->pluck('id')->all();
                        $out['document_stamp_appositions'] = array_merge($out['document_stamp_appositions'] ?? [], $found);
                    }
                }
            }
        }

        $key = 'attention:'.$signature(array_keys(self::ATTENTION_SUBJECTS));
        if (! isset($done[$key]) && Schema::hasTable('attention_items')) {
            $done[$key] = true;
            foreach (self::ATTENTION_SUBJECTS as $table => $subject) {
                if (($ids = array_values($doomed[$table] ?? [])) !== []) {
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $found = DB::table('attention_items')->where('organization_id', $organizationId)
                            ->where('subject_type', $subject)->whereIn('subject_id', $chunk)->pluck('id')->all();
                        $out['attention_items'] = array_merge($out['attention_items'] ?? [], $found);
                    }
                }
            }
        }

        $key = 'reservations:'.$signature(['sales_order_inventory_allocations', 'sales_order_procurements']);
        if (! isset($done[$key])) {
            $done[$key] = true;
            $allocations = array_values($doomed['sales_order_inventory_allocations'] ?? []);
            $procurements = array_values($doomed['sales_order_procurements'] ?? []);
            $ids = [];
            foreach (array_chunk($allocations, 500) as $chunk) {
                $ids = array_merge($ids, DB::table('sales_order_inventory_allocations')->where('organization_id', $organizationId)
                    ->whereIn('id', $chunk)->whereNotNull('inventory_reservation_id')->pluck('inventory_reservation_id')->all());
                $ids = array_merge($ids, DB::table('inventory_reservations')->where('organization_id', $organizationId)
                    ->where('reference_type', SalesOrderInventoryAllocation::class)->whereIn('reference_id', $chunk)->pluck('id')->all());
            }
            foreach (array_chunk($procurements, 500) as $chunk) {
                $ids = array_merge($ids, DB::table('inventory_reservations')->where('organization_id', $organizationId)
                    ->where('reference_type', SalesOrderProcurement::class)->whereIn('reference_id', $chunk)->pluck('id')->all());
            }
            if ($ids !== []) {
                // Only ACTIVE reservations are released + removed; consumed /
                // released ones are stock-ledger history and stay.
                $out['inventory_reservations'] = DB::table('inventory_reservations')->where('organization_id', $organizationId)
                    ->whereIn('id', array_values(array_unique($ids)))->where('status', 'active')->pluck('id')->all();
            }
        }

        $result = [];
        foreach ($out as $table => $ids) {
            $new = array_values(array_filter($this->foreignKeys->typedIds($table, $ids), fn ($id) => ! isset($doomed[$table][(string) $id])));
            if ($new !== []) {
                $result[$table] = $new;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<int,bool>> $blocked "child.column>parent" => ids
     * @return list<array<string,mixed>>
     */
    private function blockers(int $organizationId, array $blocked, array $selectedCategories): array
    {
        $roots = $this->registry->roots();
        $out = [];
        ksort($blocked);
        foreach ($blocked as $key => $ids) {
            [$link, $parent] = explode('>', $key);
            [$child, $column] = explode('.', $link);
            $categories = $this->registry->categoriesOf($child);
            $samples = [];
            if (isset($roots[$child])) {
                $samples = DB::table($child)->where('organization_id', $organizationId)
                    ->whereIn('id', array_slice(array_values($ids), 0, 5))->orderBy('id')
                    ->pluck($roots[$child]['number'])->map(fn ($v) => (string) $v)->all();
            }
            $inSelection = array_filter($categories, fn ($c) => isset($selectedCategories[$c])) !== [];
            $out[] = [
                // outside_selection: same category but not selected (date / records);
                // preserved_category: a category the user keeps; protected_reference:
                // data no selective reset can remove (e.g. Finance obligations).
                'code' => $inSelection ? 'outside_selection' : ($categories !== [] ? 'preserved_category' : 'protected_reference'),
                'table' => $child,
                'column' => $column,
                'parent_table' => $parent,
                'category' => $categories[0] ?? null,
                'count' => count($ids),
                'samples' => $samples,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, list<int|string>> $delete
     * @param array<string, list<int|string>> $unlink
     * @return array<string, array{delete: array<string,int>, unlink: array<string,int>}>
     */
    private function breakdown(SelectiveResetSelection $selection, array $delete, array $unlink): array
    {
        $out = [];
        foreach ($delete as $table => $ids) {
            $owner = 'linked';
            foreach ($selection->categories as $category) {
                if (in_array($category, $this->registry->categoriesOf($table), true)) {
                    $owner = $category;
                    break;
                }
            }
            $out[$owner]['delete'][$table] = count($ids);
        }
        foreach ($unlink as $key => $ids) {
            $out['unlinked']['unlink'][$key] = count($ids);
        }
        foreach ($out as $category => $counts) {
            $out[$category] += ['delete' => [], 'unlink' => []];
        }

        return $out;
    }

    /**
     * Deterministic, type-preserving list of a set's ids (numeric order for
     * integer keys, byte order for string keys).
     *
     * @param array<string, int|string> $set
     * @return list<int|string>
     */
    private function sortedIds(string $table, array $set): array
    {
        $list = array_values($set);
        sort($list, $this->foreignKeys->integerKey($table) ? SORT_NUMERIC : SORT_STRING);

        return $list;
    }

    /** @param array<string, list<int|string>> $delete @return array<string, list<string>> */
    private function samples(array $delete): array
    {
        $roots = $this->registry->roots();
        $out = [];
        foreach ($delete as $table => $ids) {
            if (isset($roots[$table])) {
                $out[$table] = DB::table($table)->whereIn('id', array_slice($ids, 0, 8))->orderBy('id')
                    ->pluck($roots[$table]['number'])->map(fn ($v) => (string) $v)->all();
            }
        }

        return $out;
    }

    /** @return array<string,int> */
    public function protectedCounts(int $organizationId): array
    {
        $out = [];
        foreach ($this->registry->protectedTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $out[$table] = match (true) {
                $table === 'organizations' => DB::table($table)->where('id', $organizationId)->count(),
                $table === 'users' => DB::table('organization_memberships')->where('organization_id', $organizationId)->count(),
                Schema::hasColumn($table, 'organization_id') => DB::table($table)->where('organization_id', $organizationId)->count(),
                default => 0,
            };
        }

        return $out;
    }

    /**
     * Content hash of every row the plan touches (deleted rows, unlinked rows
     * and the stock balances of released reservations): any concurrent change
     * makes the preview stale.
     *
     * @param array<string, list<int|string>> $delete
     * @param array<string, list<int|string>> $unlink
     */
    private function stateHash(int $organizationId, array $delete, array $unlink): string
    {
        $parts = [];
        $hashRows = function (string $table, array $ids) use (&$parts, $organizationId): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $chunk)->orderBy('id')->get()
                    ->map(function ($row) {
                        $values = (array) $row;
                        ksort($values);

                        return $values;
                    })->all();
                $parts[] = $table.':'.hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
        };
        foreach ($delete as $table => $ids) {
            $hashRows($table, $ids);
        }
        foreach ($unlink as $key => $ids) {
            $hashRows(explode('.', $key)[0], $ids);
        }
        if (($reservations = $delete['inventory_reservations'] ?? []) !== []) {
            $pairs = DB::table('inventory_reservations')->whereIn('id', $reservations)->get(['warehouse_id', 'product_variant_id']);
            $balances = DB::table('inventory_balances')->where('organization_id', $organizationId)
                ->where(function ($q) use ($pairs) {
                    foreach ($pairs as $pair) {
                        $q->orWhere(fn ($w) => $w->where('warehouse_id', $pair->warehouse_id)->where('product_variant_id', $pair->product_variant_id));
                    }
                })->orderBy('id')->get(['id', 'on_hand', 'reserved']);
            $parts[] = 'balances:'.hash('sha256', json_encode($balances, JSON_THROW_ON_ERROR));
        }

        return hash('sha256', implode('|', $parts));
    }
}
