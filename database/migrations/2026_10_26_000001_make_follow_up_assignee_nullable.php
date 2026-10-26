<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W1.1: follow-ups may be "Unassigned" (assigned_user_id NULL).
 *
 * Open work of a member who loses organization (or store) access is released
 * to the unassigned bucket by FollowUpAssignmentReleaser instead of staying
 * invisible under a departed user. Completed/cancelled history keeps its
 * assignee.
 *
 * Additive and in place: only the column nullability changes. The FK is
 * dropped and re-created with the same name and the same RESTRICT rule (MySQL
 * cannot MODIFY a column while it backs a constraint); existing assignments
 * and the (organization_id, assigned_user_id, status, due_*) indexes are kept —
 * B-tree indexes serve `assigned_user_id IS NULL` lookups as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_user_id')->nullable()->change();
            $table->foreign('assigned_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never invent an assignee: rolling back is only possible once no
        // follow-up is unassigned.
        if (DB::table('follow_ups')->whereNull('assigned_user_id')->exists()) {
            throw new RuntimeException('Unassigned follow-ups exist; assign them before rolling back this migration.');
        }

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_user_id')->nullable(false)->change();
            $table->foreign('assigned_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
