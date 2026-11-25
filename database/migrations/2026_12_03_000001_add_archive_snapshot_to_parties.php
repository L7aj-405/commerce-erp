<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Contacts, Phase 2: archiving a party now also takes its commercial profiles out of the pickers (customer
 * status inactive / supplier inactive). The previous state is remembered here so a restore reinstates exactly what the
 * archive switched off and nothing else. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->json('archive_snapshot')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('archive_snapshot');
        });
    }
};
