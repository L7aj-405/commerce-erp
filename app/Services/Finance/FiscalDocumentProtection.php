<?php

namespace App\Services\Finance;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal document protection (Sprint 0 / WS1) — the single definition of
 * which financial records a data reset may never delete.
 *
 * An organization is either:
 *
 *  - `protected` (default, real business data): every fiscal document
 *    created at or after `fiscal_protection_started_at` (NULL = since
 *    always) is protected;
 *  - `test` (explicitly declared test / demonstration data): nothing is
 *    protected, the reset engines may clean everything.
 *
 * Protected fiscal documents:
 *
 *  - invoices that were issued (`issued_at` set: issued or superseded —
 *    a draft never carried an official number);
 *  - credit notes that were issued;
 *  - customer payments, posted or reversed (a reversal is history of a
 *    posting, never a reason to forget it);
 *  - payment refunds;
 *  - outgoing (supplier / charge) payments, posted or reversed.
 *
 * Their lines, allocations and the documents they depend on can then never
 * be deleted either: both reset engines refuse a plan that would remove a
 * protected row, whatever path reaches it. Numbering sequences are never
 * touched by any reset (see OrganizationDataResetSequenceGuard), so a
 * number is never reused.
 *
 * The legal retention period is NOT encoded here: it must be validated by
 * the organization's accountant (see docs/audits/finance-sprint-0.md).
 */
class FiscalDocumentProtection
{
    public const MODE_PROTECTED = 'protected';

    public const MODE_TEST = 'test';

    /**
     * table => [timestamp column that dates the document's official
     *           existence, extra restriction, human reference column].
     *
     * @var array<string, array{since: string, issued_only: bool, number: string}>
     */
    private const DOCUMENTS = [
        'invoices' => ['since' => 'issued_at', 'issued_only' => true, 'number' => 'invoice_number'],
        'credit_notes' => ['since' => 'issued_at', 'issued_only' => true, 'number' => 'credit_note_number'],
        'payments' => ['since' => 'created_at', 'issued_only' => false, 'number' => 'payment_number'],
        'payment_refunds' => ['since' => 'created_at', 'issued_only' => false, 'number' => 'refund_number'],
        'finance_outgoing_payments' => ['since' => 'created_at', 'issued_only' => false, 'number' => 'payment_number'],
        // Supplier invoices: protected from approval on (a draft carries no official existence; voided ones keep approved_at).
        'supplier_invoices' => ['since' => 'approved_at', 'issued_only' => true, 'number' => 'internal_number'],
    ];

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    public function isProtecting(Organization $organization): bool
    {
        return $this->mode($organization) === self::MODE_PROTECTED;
    }

    public function mode(Organization $organization): string
    {
        return $organization->fiscal_data_mode === self::MODE_TEST ? self::MODE_TEST : self::MODE_PROTECTED;
    }

    /**
     * Protected rows of one table, or null when nothing of it is protected
     * (test mode, unknown or missing table).
     */
    public function protectedQuery(Organization $organization, string $table): ?Builder
    {
        $definition = self::DOCUMENTS[$table] ?? null;
        if ($definition === null || ! $this->isProtecting($organization) || ! Schema::hasTable($table)) {
            return null;
        }

        $query = DB::table($table)->where($table.'.organization_id', (int) $organization->getKey());
        // Test Mode rows (sandbox_id set) are never fiscal documents: they are
        // owned and reset by the Sandbox, not protected here.
        if (Schema::hasColumn($table, 'sandbox_id')) {
            $query->whereNull($table.'.sandbox_id');
        }
        if ($definition['issued_only']) {
            $query->whereNotNull($table.'.'.$definition['since']);
        }
        if ($organization->fiscal_protection_started_at !== null) {
            // Protected from the start instant on; rows dated before it are
            // explicitly declared test data. A NULL date (legacy row) is
            // treated as protected — never silently as test data.
            $query->where(fn (Builder $q) => $q->whereNull($table.'.'.$definition['since'])
                ->orWhere($table.'.'.$definition['since'], '>=', $organization->fiscal_protection_started_at->copy()->utc()->format('Y-m-d H:i:s')));
        }

        return $query;
    }

    /** Whether at least one protected fiscal document exists in the organization. */
    public function hasProtectedDocuments(Organization $organization): bool
    {
        foreach ($this->tables() as $table) {
            if ($this->protectedQuery($organization, $table)?->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,int> table => protected rows (empty in test mode) */
    public function protectedCounts(Organization $organization): array
    {
        $out = [];
        foreach ($this->tables() as $table) {
            $query = $this->protectedQuery($organization, $table);
            if ($query !== null) {
                $out[$table] = $query->count();
            }
        }

        return $out;
    }

    /**
     * Selective reset: protected rows among the ids a plan would delete.
     *
     * @param  array<string, list<int|string>>  $delete  table => ids
     * @return list<array{code:string,table:string,count:int,samples:list<string>}>
     */
    public function selectiveBlockers(Organization $organization, array $delete): array
    {
        $blockers = [];
        foreach ($this->tables() as $table) {
            $ids = $delete[$table] ?? [];
            if ($ids === [] || $this->protectedQuery($organization, $table) === null) {
                continue;
            }
            $count = 0;
            $samples = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += $this->protectedQuery($organization, $table)->whereIn($table.'.id', $chunk)->count();
                if (count($samples) < 5) {
                    $samples = array_merge($samples, $this->protectedQuery($organization, $table)->whereIn($table.'.id', $chunk)
                        ->orderBy($table.'.id')->limit(5 - count($samples))->pluck(self::DOCUMENTS[$table]['number'])
                        ->map(fn ($value) => (string) $value)->all());
                }
            }
            if ($count > 0) {
                $blockers[] = ['code' => 'fiscal_protected', 'table' => $table, 'count' => $count, 'samples' => $samples];
            }
        }

        return $blockers;
    }

    /**
     * Full-module reset: protected rows among a deletion sub-query.
     */
    public function protectedAmong(Organization $organization, string $table, Builder $deletedIds): int
    {
        $query = $this->protectedQuery($organization, $table);

        return $query === null ? 0 : $query->whereIn($table.'.id', $deletedIds)->count();
    }
}
