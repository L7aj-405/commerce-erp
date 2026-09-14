<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Finance Account was previously compatible with exactly the Payment
 * methods implied by its single `type` (via config('payments.account_types')
 * — e.g. type=bank silently accepted both `card` and `bank_transfer`). That
 * forced a company with one bank account receiving transfers, TPE
 * settlements AND cheques to either misuse the type mapping or create
 * duplicate accounts per method.
 *
 * This adds an explicit, per-account `accepted_methods` JSON list (real
 * Payment::method values only — cash/card/bank_transfer/cheque — never a
 * fabricated method) and backfills every existing account from the exact
 * config('payments.account_types') mapping it was already relying on, so no
 * account gains or loses compatibility on migrate. `type` is untouched and
 * keeps its existing categorisation role; only the compatibility check in
 * RecordPaymentAction moves to this new explicit list.
 *
 * Safe to re-run after a partial failure: MySQL's ALTER TABLE (the
 * Schema::table() call below) commits on its own regardless of the
 * migration's surrounding transaction, so a failure during the backfill
 * loop can leave the column already created with every row still NULL.
 * Both the column-add and the backfill are guarded to be idempotent —
 * `up()` can run again from either state and converges on the same result.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('financial_accounts', 'accepted_methods')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->json('accepted_methods')->nullable()->after('type');
            });
        }

        // Build type => [methods] from the existing method => [types] config,
        // the exact inverse of what RecordPaymentAction::assertCompatible()
        // has always checked — so every account keeps precisely the
        // compatibility it already had.
        $methodsByType = [];
        foreach ((array) config('payments.account_types', []) as $method => $types) {
            foreach ((array) $types as $type) {
                $methodsByType[$type][] = $method;
            }
        }

        // Only rows never backfilled (still NULL) are touched, so re-running
        // this after a partial failure never re-derives — and never
        // overwrites — a row an operator may since have edited by hand.
        DB::table('financial_accounts')
            ->whereNull('accepted_methods')
            ->select('id', 'type')
            ->orderBy('id')
            ->chunkById(500, function ($accounts) use ($methodsByType) {
                foreach ($accounts as $account) {
                    DB::table('financial_accounts')
                        ->where('id', $account->id)
                        ->update(['accepted_methods' => json_encode($methodsByType[$account->type] ?? [])]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropColumn('accepted_methods');
        });
    }
};
