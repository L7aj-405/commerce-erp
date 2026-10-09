<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-W1 — per-member default POS warehouse.
 *
 * `default_warehouse_id` is the warehouse pre-selected for this member when
 * they open the POS. It is a UX preference only: it grants no warehouse access
 * and never restricts which warehouse the member may choose.
 *
 * Stored on the membership (not on users) because a user can belong to several
 * organizations. The composite (organization_id, default_warehouse_id) FK makes
 * a cross-tenant reference impossible at the database level. It is RESTRICT on
 * delete because a composite SET NULL is illegal (organization_id is NOT NULL);
 * warehouses are never hard-deleted by the application, and the backup restorer
 * clears orphaned defaults explicitly. Existing rows stay NULL — no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('default_warehouse_id')->nullable()->after('status');
            $table->index(['organization_id', 'default_warehouse_id'], 'org_member_default_wh_idx');
            $table->foreign(['organization_id', 'default_warehouse_id'], 'org_member_default_wh_fk')
                ->references(['organization_id', 'id'])
                ->on('warehouses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->dropForeign('org_member_default_wh_fk');
            $table->dropIndex('org_member_default_wh_idx');
            $table->dropColumn('default_warehouse_id');
        });
    }
};
