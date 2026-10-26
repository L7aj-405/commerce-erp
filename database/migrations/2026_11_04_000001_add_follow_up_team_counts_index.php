<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W5 read paths, measured on MySQL 8.4 (300k follow-ups, 1.2M audit rows).
 * Indexes only — no data change, no new table.
 *
 * - follow_up_team_counts_idx: covering index for TeamWorkQuery's grouped
 *   bucket counts (GROUP BY assigned_user_id); without it each count read
 *   ~150k follow-up rows (≈2 s each).
 * - audit_subject_timeline_idx: covering index for BusinessTimeline's audit
 *   lookup by subject; ids and timestamps are sorted from the index, then
 *   only one page of full rows is read (was ≈0.6 s per customer page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->index(['organization_id', 'status', 'store_id', 'assigned_user_id', 'due_date', 'due_at', 'completed_at'], 'follow_up_team_counts_idx');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            // created_at before event: an event IN list as a range keypart multiplied the ranges (measured ×5 slower).
            $table->index(['auditable_type', 'auditable_id', 'organization_id', 'created_at', 'event'], 'audit_subject_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_subject_timeline_idx');
        });
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_up_team_counts_idx');
        });
    }
};
