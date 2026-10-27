<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Historical 0% tier sale entries were written as `pending` (some later
 * approved) although nothing is payable. Re-label only the unambiguous ones:
 *
 * 1. Original SALE entries with commission rate snapshot AND commission
 *    amount exactly zero, still `pending` or `approved` — a `paid` entry is a
 *    recorded payout event and is left as-is.
 * 2. Then their reversals (return / cancellation / correction, i.e. what
 *    AppendCommissionReversalAction writes) linked through source_entry_id to
 *    a sale that is now `no_commission` in the same organization, with a
 *    commission amount exactly zero, still `pending` or `approved`.
 *
 * Manual adjustments and unlinked entries are never touched. Sale entries are
 * only ever created after a successful evaluation (salesperson, available
 * cost, margin and a matched tier), so a zero rate here is the matched tier's
 * rate, never missing source data. Status-only update; snapshots and approval
 * audit columns are kept. Idempotent: a re-run matches nothing.
 */
return new class extends Migration
{
    private const REVERSAL_TYPES = ['return_reversal', 'cancellation', 'correction'];

    public function up(): void
    {
        DB::table('commission_entries')
            ->where('entry_type', 'sale')
            ->whereIn('status', ['pending', 'approved'])
            ->where('commission_rate_snapshot', 0)
            ->where('commission_amount', 0)
            ->update(['status' => 'no_commission', 'updated_at' => now()]);

        // Ids are selected through the self-join first, then updated by id:
        // MySQL refuses an UPDATE whose subquery reads the same table.
        DB::table('commission_entries as reversal')
            ->join('commission_entries as source', function ($join) {
                $join->on('source.id', '=', 'reversal.source_entry_id')
                    ->on('source.organization_id', '=', 'reversal.organization_id');
            })
            ->whereIn('reversal.entry_type', self::REVERSAL_TYPES)
            ->whereIn('reversal.status', ['pending', 'approved'])
            ->where('reversal.commission_amount', 0)
            ->where('source.entry_type', 'sale')
            ->where('source.status', 'no_commission')
            ->select('reversal.id')
            ->chunkById(1000, function ($rows) {
                DB::table('commission_entries')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['status' => 'no_commission', 'updated_at' => now()]);
            }, 'reversal.id', 'id');
    }

    /**
     * Restores the pre-status lifecycle value so older code never sees an
     * unknown status: `approved` where approval metadata exists, otherwise
     * `pending` (the only two statuses up() ever relabels).
     */
    public function down(): void
    {
        DB::table('commission_entries')
            ->where('status', 'no_commission')
            ->update(['status' => DB::raw("CASE WHEN approved_at IS NULL THEN 'pending' ELSE 'approved' END")]);
    }
};
