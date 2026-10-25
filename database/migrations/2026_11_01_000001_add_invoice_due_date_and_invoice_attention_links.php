<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W4.4: invoice payment attention.
 *
 * - invoices.due_date: business-local calendar date, stamped once at issue
 *   (invoice_date + documents.invoice.payment_term_days; corrections keep
 *   the replaced invoice's due date). Already issued/superseded invoices are
 *   backfilled the same way (invoice data is otherwise untouched). Index for
 *   the detector's candidate scan (organization_id, status, due_date).
 * - follow_ups.invoice_id: explicit invoice link (like quotation_id: single
 *   column, SET NULL), validated by SaveFollowUpAction (tenant, invoices.view,
 *   store access, customer consistency; store derived from the invoice).
 * - attention_items.invoice_id: convenience FK for invoice episodes
 *   (subject_type "invoice"), mirroring quotation_id.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('invoice_date');
            $table->index(['organization_id', 'status', 'due_date'], 'invoice_org_status_due_idx');
        });

        $days = max(0, (int) config('documents.invoice.payment_term_days', 30));
        // Set-based and portable (no driver-specific date SQL): one update per distinct invoice date.
        // One set-based statement (measured: a per-date loop took minutes on 50k invoices).
        $dueDate = match (DB::connection()->getDriverName()) {
            'sqlite' => DB::raw("date(invoice_date, '+{$days} days')"),
            default => DB::raw("DATE_ADD(invoice_date, INTERVAL {$days} DAY)"), // mysql / mariadb
        };
        DB::table('invoices')->whereIn('status', ['issued', 'superseded'])->whereNull('due_date')->update(['due_date' => $dueDate]);

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('quotation_id')->constrained('invoices')->nullOnDelete();
            $table->index(['organization_id', 'invoice_id'], 'follow_up_invoice_idx');
        });

        Schema::table('attention_items', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('quotation_id')->constrained('invoices')->nullOnDelete();
            $table->index(['organization_id', 'invoice_id'], 'attention_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attention_items', function (Blueprint $table) {
            $table->dropIndex('attention_invoice_idx');
            $table->dropForeign(['invoice_id']);
            $table->dropColumn('invoice_id');
        });
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_up_invoice_idx');
            $table->dropForeign(['invoice_id']);
            $table->dropColumn('invoice_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoice_org_status_due_idx');
            $table->dropColumn('due_date');
        });
    }
};
