<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-issue correction workflow.
 *
 * A correction is a new Invoice row that starts as a draft, carries a copy of
 * the original's immutable snapshots, and points back at the Invoice it
 * replaces. When the correction is issued, the original moves to the
 * `superseded` status (an app-level enum value on the existing string column —
 * no schema change needed for that).
 *
 * Depends on `invoices` (2026_08_23_000006_create_document_tables) and the
 * earlier `2026_09_09_000002` columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('corrected_invoice_id')->nullable()->after('sales_order_id');
            $table->text('correction_reason')->nullable()->after('cancellation_reason');

            $table->index(['organization_id', 'corrected_invoice_id'], 'invoice_correction_idx');
            $table->foreign(['organization_id', 'corrected_invoice_id'], 'invoice_correction_fk')
                ->references(['organization_id', 'id'])->on('invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoice_correction_fk');
            $table->dropIndex('invoice_correction_idx');
            $table->dropColumn(['corrected_invoice_id', 'correction_reason']);
        });
    }
};
