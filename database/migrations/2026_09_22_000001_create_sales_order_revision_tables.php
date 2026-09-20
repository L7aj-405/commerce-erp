<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial correction history for Sales Orders.
 *
 * The SalesOrder row remains the current commercial projection so existing
 * payments keep their original allocation target. Each controlled correction
 * freezes the complete before/after commercial state here. Historical Invoice
 * rows are never rewritten; a replacement Invoice points at the revision that
 * produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedInteger('revision_number');
            $table->string('status', 24)->default('in_progress');
            $table->text('reason');
            $table->json('before_snapshot');
            $table->json('after_snapshot')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('initiated_at');
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'sales_revision_org_id_unique');
            $table->unique(['organization_id', 'sales_order_id', 'revision_number'], 'sales_revision_number_unique');
            $table->foreign(['organization_id', 'store_id'], 'sales_revision_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'sales_revision_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('current_revision_id')->nullable()->after('order_number');
            $table->foreign(['organization_id', 'current_revision_id'], 'sales_order_current_revision_fk')
                ->references(['organization_id', 'id'])->on('sales_order_revisions')->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_revision_id')->nullable()->after('sales_order_id');
            $table->index(['organization_id', 'sales_order_revision_id'], 'invoice_sales_revision_idx');
            $table->foreign(['organization_id', 'sales_order_revision_id'], 'invoice_sales_revision_fk')
                ->references(['organization_id', 'id'])->on('sales_order_revisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoice_sales_revision_fk');
            $table->dropIndex('invoice_sales_revision_idx');
            $table->dropColumn('sales_order_revision_id');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign('sales_order_current_revision_fk');
            $table->dropColumn('current_revision_id');
        });
        Schema::dropIfExists('sales_order_revisions');
    }
};
