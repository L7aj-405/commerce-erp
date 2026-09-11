<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-issue revision workflow for Devis.
 *
 * A revision is a new Quotation row that starts as a draft, carries a copy of
 * the issued Devis' immutable snapshots (customer identity, seller snapshot,
 * representative, notes, terms and every QuotationLine — quantity / price /
 * discount / tax / totals basis), and stays inside the SAME commercial
 * proposal chain:
 *
 *   - `root_quotation_id`        → the first issued Devis of the chain (DEV-N/YYYY)
 *   - `revised_from_quotation_id`→ the immediate predecessor it was created from
 *   - `revision_number`          → 0 for the original, 1 for "Révision 1", …
 *   - `revision_reason`          → "Motif de la révision" captured at start
 *
 * When the revision is issued it takes the number `DEV-N/YYYY-R{n}` (it never
 * consumes a fresh sequence value) and the version it replaces moves to the
 * app-level `superseded` status (existing string column — no schema change).
 *
 * Composite tenant FKs on a nullable column use RESTRICT (never SET NULL,
 * because `organization_id` is NOT NULL) — same rule as the rest of the
 * quotation schema.
 *
 * Depends on `quotations` (2026_09_11_000001_create_quotation_tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->unsignedBigInteger('root_quotation_id')->nullable()->after('converted_sales_order_id');
            $table->unsignedBigInteger('revised_from_quotation_id')->nullable()->after('root_quotation_id');
            $table->unsignedInteger('revision_number')->default(0)->after('revised_from_quotation_id');
            $table->text('revision_reason')->nullable()->after('rejection_reason');

            $table->index(['organization_id', 'root_quotation_id'], 'quotation_revision_root_idx');

            $table->foreign(['organization_id', 'root_quotation_id'], 'quotation_revision_root_fk')
                ->references(['organization_id', 'id'])->on('quotations')->restrictOnDelete();

            $table->foreign(['organization_id', 'revised_from_quotation_id'], 'quotation_revision_from_fk')
                ->references(['organization_id', 'id'])->on('quotations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign('quotation_revision_root_fk');
            $table->dropForeign('quotation_revision_from_fk');
            $table->dropIndex('quotation_revision_root_idx');
            $table->dropColumn(['root_quotation_id', 'revised_from_quotation_id', 'revision_number', 'revision_reason']);
        });
    }
};
