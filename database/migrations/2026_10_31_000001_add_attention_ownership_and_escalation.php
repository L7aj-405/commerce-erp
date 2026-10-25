<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W4.3: attention ownership and one escalation level (tenant/business only).
 *
 * - assigned_user_id: the ERP user currently responsible for the item (not
 *   the quotation creator/issuer, nor a follow-up assignee). Kept on
 *   resolved/dismissed history; SET NULL only if a user row is deleted.
 * - assignment_source: issuer | creator | fallback | claim | manual — "manual"
 *   with no assignee means a manager deliberately unassigned it (the
 *   background never re-assigns it then).
 * - ownership_resolved_at: the episode's initial owner resolution ran.
 * - critical_since: when the episode first became critical (escalation clock).
 * - escalated_at: the episode escalated (at most once).
 *
 * Additive. Existing critical items start their escalation clock at
 * migration time (no escalation burst on deploy); owners are resolved by the
 * next scan. Index for "Mine" / per-assignee views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attention_items', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('quotation_id')->constrained('users')->nullOnDelete();
            $table->string('assignment_source', 16)->nullable()->after('assigned_user_id');
            $table->timestamp('ownership_resolved_at')->nullable()->after('assignment_source');
            $table->timestamp('critical_since')->nullable()->after('urgency');
            $table->timestamp('escalated_at')->nullable()->after('notified_at');
            $table->index(['organization_id', 'assigned_user_id', 'status'], 'attention_org_assignee_idx');
        });

        DB::table('attention_items')->where('severity', 'critical')->whereNotNull('active_key')->update(['critical_since' => now()]);
    }

    public function down(): void
    {
        Schema::table('attention_items', function (Blueprint $table) {
            $table->dropIndex('attention_org_assignee_idx');
            $table->dropForeign(['assigned_user_id']);
            $table->dropColumn(['assigned_user_id', 'assignment_source', 'ownership_resolved_at', 'critical_since', 'escalated_at']);
        });
    }
};
