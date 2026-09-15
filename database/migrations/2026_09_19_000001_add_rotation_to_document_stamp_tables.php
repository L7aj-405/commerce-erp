<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds configurable stamp rotation. Additive only — the baseline
 * `2026_09_18_000001_create_organization_document_stamp_tables` migration has
 * already run locally and must not be edited (see MIGRATION_BASELINE.md).
 *
 * `rotation_deg` on `organization_document_stamps` is the organization's
 * current configured angle (append-only versioned, same as
 * position_anchor/offset/width — see SaveOrganizationDocumentStampAction).
 * `rotation_deg` on `document_stamp_appositions` is the frozen angle that was
 * active at the moment of apposition — same immutability guarantee as the
 * other frozen columns on that table. Both default to 0 (perfectly
 * horizontal) so existing rows stay valid with no backfill needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_document_stamps', function (Blueprint $table) {
            $table->decimal('rotation_deg', 6, 2)->default(0)->after('display_width_mm');
        });

        Schema::table('document_stamp_appositions', function (Blueprint $table) {
            $table->decimal('rotation_deg', 6, 2)->default(0)->after('display_width_mm');
        });
    }

    public function down(): void
    {
        Schema::table('document_stamp_appositions', function (Blueprint $table) {
            $table->dropColumn('rotation_deg');
        });

        Schema::table('organization_document_stamps', function (Blueprint $table) {
            $table->dropColumn('rotation_deg');
        });
    }
};
