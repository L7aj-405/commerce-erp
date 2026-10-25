<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W3: recurring follow-up definitions, follow-up templates, and the link from
 * a generated follow-up occurrence to its rule.
 *
 * follow_up_recurring_rules — the rule only; every occurrence is a normal
 * follow_ups row (own reminder, version, audit, history). Recurrence keeps
 * business-local intent: `local_time` (H:i, NULL = date-only occurrences) and
 * the date cursor `next_occurrence_on` are organization-local; only
 * `next_generate_at` (when the scheduler should generate the cursor
 * occurrence) is a UTC instant, recomputed on timezone changes. Links mirror
 * follow_ups (single-column SET NULL for customer/quotation, composite store
 * FK, RESTRICT assignee) so resets/deletes never leave dangling references.
 *
 * follow_ups.recurring_rule_id + recurrence_key (the occurrence's local date)
 * with UNIQUE (organization_id, recurring_rule_id, recurrence_key): the
 * database guarantees one occurrence per rule and date, whatever the number
 * of concurrent generators. Manual follow-ups keep both NULL (MySQL/SQLite
 * unique indexes ignore NULLs).
 *
 * follow_up_templates — organization-wide pre-fill presets for the manual
 * create form; never executed automatically.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_recurring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();

            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();

            $table->string('title', 200);
            $table->text('note')->nullable();
            $table->string('priority', 16)->default('normal');

            $table->string('frequency', 16);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->string('local_time', 5)->nullable();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_generated')->default(0);

            $table->string('status', 16)->default('active');
            $table->string('status_reason', 40)->nullable();
            $table->date('last_generated_on')->nullable();
            $table->date('next_occurrence_on')->nullable();
            $table->timestamp('next_generate_at')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'follow_up_rule_org_id_unique');
            $table->index(['status', 'next_generate_at'], 'follow_up_rule_due_idx');
            $table->index(['organization_id', 'status', 'assigned_user_id'], 'follow_up_rule_org_status_idx');
            $table->foreign(['organization_id', 'store_id'], 'follow_up_rule_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
        });

        Schema::create('follow_up_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('title', 200);
            $table->text('default_note')->nullable();
            $table->string('default_priority', 16)->default('normal');
            $table->unsignedSmallInteger('default_due_offset_days')->nullable();
            $table->string('default_time', 5)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'name'], 'follow_up_template_org_name_unique');
            $table->index(['organization_id', 'active', 'name'], 'follow_up_template_active_idx');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('recurring_rule_id')->nullable()->after('quotation_id')
                ->constrained('follow_up_recurring_rules')->nullOnDelete();
            $table->date('recurrence_key')->nullable()->after('recurring_rule_id');
            $table->unique(['organization_id', 'recurring_rule_id', 'recurrence_key'], 'follow_up_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropUnique('follow_up_occurrence_unique');
            $table->dropForeign(['recurring_rule_id']);
            $table->dropColumn(['recurring_rule_id', 'recurrence_key']);
        });
        Schema::dropIfExists('follow_up_templates');
        Schema::dropIfExists('follow_up_recurring_rules');
    }
};
