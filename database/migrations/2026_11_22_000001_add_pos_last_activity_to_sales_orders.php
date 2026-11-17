<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS draft lifecycle — additive and backward compatible.
 *
 * sales_orders.pos_last_activity_at records the last MEANINGFUL cart change
 * (line added / changed / removed, customer or header edited, draft resumed).
 * Page loads and background refreshes never write it, so inactivity expiry is
 * measured from real operator activity rather than the creation date.
 *
 * Legacy POS drafts keep NULL: cleanup then falls back to updated_at, which is
 * the more conservative (later) of the two signals. No data is rewritten and
 * nothing is deleted by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('pos_last_activity_at')->nullable()->after('pos_held_at');
            $table->index(['organization_id', 'sandbox_id', 'source', 'status', 'pos_last_activity_at'], 'so_pos_draft_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('so_pos_draft_expiry_idx');
            $table->dropColumn('pos_last_activity_at');
        });
    }
};
