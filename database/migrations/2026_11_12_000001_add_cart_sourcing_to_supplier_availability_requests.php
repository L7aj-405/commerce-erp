<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2 — stock conflict recovery & supplier sourcing from the POS cart.
 *
 * - quotation_supplier_availability_requests: the ONE pre-sale supplier
 *   availability evidence table now also serves a POS cart line. A row belongs
 *   to exactly one context: a Devis line (quotation_id + quotation_line_id) OR a
 *   cart line (sales_order_id + sales_order_line_id — the cart IS a draft POS
 *   Sales Order, so the evidence is already attached to the line that will be
 *   procured). Still never a purchase order, a reservation or a stock movement.
 *
 * - sales_orders.pos_converted_at: the moment a POS cart was handed over to the
 *   regular Sales Order workflow as a Sales Order Draft (Approvisionnement →
 *   purchase order → receipt → confirmation). Such a draft leaves the POS
 *   active / held queues and can no longer be checked out at the POS.
 *
 * - sales_order_procurements: one supplier answer is the evidence of at most one
 *   procurement requirement (DB-level dedupe of the evidence → requirement step).
 *
 * Additive / relaxing only. Constraints are named explicitly (MySQL 64-char
 * limit); composite tenant FKs are RESTRICT, never SET NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_supplier_availability_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('quotation_id')->nullable()->change();
            $table->unsignedBigInteger('quotation_line_id')->nullable()->change();

            $table->unsignedBigInteger('sales_order_id')->nullable()->after('quotation_line_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable()->after('sales_order_id');

            $table->index(['organization_id', 'sales_order_id'], 'qsar_sales_order_idx');
            $table->index(['organization_id', 'sales_order_line_id', 'status'], 'qsar_so_line_status_idx');

            $table->foreign(['organization_id', 'sales_order_id'], 'qsar_sales_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            // Removing a draft cart line deletes its requests explicitly first
            // (RemoveSalesOrderLineAction). RESTRICT, never cascade / SET NULL.
            $table->foreign(['organization_id', 'sales_order_line_id'], 'qsar_so_line_fk')
                ->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('pos_converted_at')->nullable()->after('pos_held_at');
        });

        Schema::table('sales_order_procurements', function (Blueprint $table) {
            $table->unique(['organization_id', 'source_supplier_request_id'], 'proc_src_request_uq');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_procurements', function (Blueprint $table) {
            $table->dropUnique('proc_src_request_uq');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('pos_converted_at');
        });

        Schema::table('quotation_supplier_availability_requests', function (Blueprint $table) {
            $table->dropForeign('qsar_so_line_fk');
            $table->dropForeign('qsar_sales_order_fk');
            $table->dropIndex('qsar_so_line_status_idx');
            $table->dropIndex('qsar_sales_order_idx');
            $table->dropColumn(['sales_order_line_id', 'sales_order_id']);
        });
    }
};
