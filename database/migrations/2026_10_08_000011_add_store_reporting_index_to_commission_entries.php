<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commission dashboard's store filter / store breakdown is the only
 * reporting access path not already covered by the C5 composite indexes
 * (org+occurred, org+salesperson+occurred, org+status+occurred,
 * org+type+occurred). Additive index only; no data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->index(['organization_id', 'store_id', 'occurred_at'], 'ce_org_store_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->dropIndex('ce_org_store_occurred_idx');
        });
    }
};
