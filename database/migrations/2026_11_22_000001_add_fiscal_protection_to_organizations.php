<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 0 / WS1 — fiscal document protection.
 *
 * - organizations.fiscal_data_mode: `protected` (real business data, the
 *   default for every existing and new organization) or `test` (explicitly
 *   declared test / demonstration data).
 * - organizations.fiscal_protection_started_at: in `protected` mode, fiscal
 *   documents (issued invoices and credit notes, payments, refunds, outgoing
 *   payments) created at or after this instant can never be removed by a data
 *   reset. NULL means "since always": every fiscal document is protected.
 *   Existing organizations therefore keep NULL — everything they already
 *   issued or posted is protected, nothing is reinterpreted as test data.
 *
 * Additive only; no business row is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('fiscal_data_mode', 16)->default('protected')->after('status');
            $table->timestamp('fiscal_protection_started_at')->nullable()->after('fiscal_data_mode');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['fiscal_data_mode', 'fiscal_protection_started_at']);
        });
    }
};
