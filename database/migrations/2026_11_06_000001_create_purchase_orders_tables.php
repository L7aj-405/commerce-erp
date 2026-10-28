<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-quotation procurement P1.
 *
 * - sales_order_procurements gains nullable source columns: the accepted
 *   quotation, its line and the supplier availability answer a requirement was
 *   derived from. The supplier answer itself is never modified.
 *
 * - purchase_orders / purchase_order_lines: the real supplier commitment
 *   (bon de commande fournisseur, BCF-000001). One order covers one or more
 *   procurement requirements of the same supplier and store; each line points at
 *   the requirement (and its Sales Order line) it orders.
 *
 * Additive only. Every constraint is named explicitly (MySQL 64-char limit) and
 * every composite tenant FK is RESTRICT, never SET NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_procurements', function (Blueprint $table) {
            $table->unsignedBigInteger('source_quotation_id')->nullable()->after('product_variant_id');
            $table->unsignedBigInteger('source_quotation_line_id')->nullable()->after('source_quotation_id');
            $table->unsignedBigInteger('source_supplier_request_id')->nullable()->after('source_quotation_line_id');

            $table->foreign(['organization_id', 'source_quotation_id'], 'proc_src_quote_fk')
                ->references(['organization_id', 'id'])->on('quotations')->restrictOnDelete();
            $table->foreign(['organization_id', 'source_quotation_line_id'], 'proc_src_qline_fk')
                ->references(['organization_id', 'id'])->on('quotation_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'source_supplier_request_id'], 'proc_src_qsar_fk')
                ->references(['organization_id', 'id'])->on('quotation_supplier_availability_requests')->restrictOnDelete();
        });

        Schema::create('purchase_order_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->foreign('organization_id', 'po_seq_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('purchase_order_number', 32);
            // draft | ordered | cancelled
            $table->string('status', 16);
            $table->char('currency_code', 3);
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            // Client-generated id of the "Créer la commande fournisseur" submit: a retry returns the same order.
            $table->uuid('client_operation_id')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('ordered_by_user_id')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'po_org_id_unique');
            $table->unique(['organization_id', 'purchase_order_number'], 'po_number_unique');
            $table->unique(['organization_id', 'client_operation_id'], 'po_client_op_unique');
            $table->index(['organization_id', 'status'], 'po_status_idx');
            $table->index(['organization_id', 'supplier_id', 'status'], 'po_supplier_status_idx');

            $table->foreign('organization_id', 'po_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'po_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'po_supplier_fk')
                ->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'po_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('ordered_by_user_id', 'po_ordered_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by_user_id', 'po_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('sales_order_procurement_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedInteger('position');
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'pol_org_id_unique');
            $table->index(['organization_id', 'sales_order_procurement_id'], 'pol_procurement_idx');
            $table->index(['organization_id', 'sales_order_id'], 'pol_order_idx');

            $table->foreign('organization_id', 'pol_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'purchase_order_id'], 'pol_po_fk')
                ->references(['organization_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_procurement_id'], 'pol_procurement_fk')
                ->references(['organization_id', 'id'])->on('sales_order_procurements')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'pol_sales_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_line_id'], 'pol_sales_line_fk')
                ->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'pol_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_order_sequences');

        Schema::table('sales_order_procurements', function (Blueprint $table) {
            $table->dropForeign('proc_src_qsar_fk');
            $table->dropForeign('proc_src_qline_fk');
            $table->dropForeign('proc_src_quote_fk');
            $table->dropColumn(['source_quotation_id', 'source_quotation_line_id', 'source_supplier_request_id']);
        });
    }
};
