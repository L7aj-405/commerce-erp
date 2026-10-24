<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work Center (W1): canonical follow-ups.
 *
 * Due semantics (organization business timezone, see BusinessTime):
 * - date-only follow-up: `due_date` set, `due_at` NULL — a calendar date that
 *   is never shifted through UTC and becomes overdue once that local day ends;
 * - timed follow-up: `due_at` holds the exact UTC instant and `due_date` the
 *   organization-local date it fell on when saved (ordering / date filters);
 * - undated follow-up: both NULL.
 *
 * `version` is an optimistic-concurrency counter bumped by every mutation so
 * stale tabs (and later MCP/API/offline clients) cannot silently overwrite.
 *
 * Links to customers/quotations are single-column FKs with SET NULL (same
 * pattern as organization_contacts): a composite (organization_id, fk) FK
 * cannot SET NULL, and deleting a customer/quotation must never be blocked by
 * work items. Tenant consistency of those links is enforced by
 * SaveFollowUpAction. The store reference is composite (stores are never
 * deleted by data resets).
 *
 * Appended after 2026_08_23_000010 per MIGRATION_BASELINE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();

            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();

            $table->string('title', 200);
            $table->text('note')->nullable();

            $table->date('due_date')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->string('priority', 16)->default('normal');
            $table->string('status', 16)->default('open');

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('outcome_note')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'follow_up_org_id_unique');
            $table->index(['organization_id', 'assigned_user_id', 'status', 'due_date'], 'follow_up_assignee_date_idx');
            $table->index(['organization_id', 'assigned_user_id', 'status', 'due_at'], 'follow_up_assignee_time_idx');
            $table->index(['organization_id', 'status', 'due_date'], 'follow_up_org_status_date_idx');
            $table->index(['organization_id', 'customer_id'], 'follow_up_customer_idx');
            $table->index(['organization_id', 'quotation_id'], 'follow_up_quotation_idx');

            $table->foreign(['organization_id', 'store_id'], 'follow_up_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
