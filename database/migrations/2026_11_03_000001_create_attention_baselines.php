<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4.5.1: safe first rollout of an attention domain (Sales Order receivables).
 *
 * - attention_baselines: per organization and subject type, when the
 *   domain's first complete scan was recorded (AttentionBaselines). Until it
 *   exists, the domain's items are visible but never notified/escalated;
 *   UNIQUE (organization_id, subject_type) keeps concurrent first scans from
 *   both establishing it. Operational state, not business data (not part of
 *   organization backups; a data reset leaves it, so new orders behave
 *   normally afterwards).
 * - attention_items.escalation_exempt: an episode that was already critical
 *   when its domain was baselined — historical, so it never auto-escalates.
 *   Any later change opens a new episode (new row, not exempt).
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attention_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 32);
            $table->timestamp('established_at');
            $table->timestamps();

            $table->unique(['organization_id', 'subject_type'], 'attention_baseline_unique');
        });

        Schema::table('attention_items', function (Blueprint $table) {
            $table->boolean('escalation_exempt')->default(false)->after('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('attention_items', function (Blueprint $table) {
            $table->dropColumn('escalation_exempt');
        });
        Schema::dropIfExists('attention_baselines');
    }
};
