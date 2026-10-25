<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W4.1 Attention Engine: situations that deserve a human decision. Detection
 * never acts on the business document; people snooze, dismiss or create a
 * follow-up. Generic subject (`subject_type` / `subject_id`) so later phases
 * can add invoices, orders or stock; W4.1 only writes quotation items.
 *
 * Episodes: one row per (type, subject, episode). While the condition holds,
 * the episode's row is "active" and carries `active_key`
 * (`{type}:{subject_type}:{subject_id}`); UNIQUE (organization_id,
 * active_key) guarantees one active episode per condition under any number of
 * concurrent detectors (MySQL/SQLite unique indexes ignore NULL). When the
 * condition disappears the episode closes (`resolved_at`, `active_key` NULL);
 * a later reappearance is a new episode. Rows are history — never deleted
 * when a condition ends.
 *
 * Links mirror follow_ups: single-column SET NULL for customer/quotation,
 * composite store FK. Also adds quotation_org_status_idx (additive) for the
 * detector's candidate query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attention_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();

            $table->string('type', 48);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('episode')->default(1);
            $table->string('active_key', 120)->nullable();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();

            $table->string('severity', 16);
            $table->string('status', 16)->default('open');
            $table->string('title', 200);
            $table->string('summary', 500);
            $table->json('metadata')->nullable();

            $table->timestamp('detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dismiss_note', 500)->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'active_key'], 'attention_active_unique');
            $table->unique(['organization_id', 'type', 'subject_type', 'subject_id', 'episode'], 'attention_episode_unique');
            $table->index(['organization_id', 'status', 'severity'], 'attention_org_status_idx');
            $table->index(['organization_id', 'last_detected_at'], 'attention_org_detected_idx');
            $table->index(['organization_id', 'quotation_id'], 'attention_quotation_idx');
            $table->foreign(['organization_id', 'store_id'], 'attention_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
        });

        // Detector candidate scan (organization_id, status IN (issued, expired), by id):
        // measured on MySQL 8.4 to walk the PRIMARY key without it.
        Schema::table('quotations', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'quotation_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotation_org_status_idx');
        });
        Schema::dropIfExists('attention_items');
    }
};
