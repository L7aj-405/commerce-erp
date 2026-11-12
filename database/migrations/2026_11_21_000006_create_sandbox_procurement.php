<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — isolated Test procurement & supplier purchasing ledger.
 *
 * Physically separate mirrors of the Production procurement transaction tables
 * (supplier sourcing requests, procurement requirements, purchase orders and
 * lines, goods receipts). The procurement models route to them only while a
 * Test context is active, so Production purchase orders, requirements,
 * receipts and their reports can never see — or be changed by — a Test record.
 * Suppliers, supplier catalogue, products, stores, warehouses and users stay
 * shared master data (referenced, never copied).
 *
 * Internal references are composite (sandbox_id, id) foreign keys, including
 * the receipt's links to the Test inventory ledger (movement, reservation,
 * allocation): a Test row can never point at a Production row or at another
 * sandbox. Links to sales orders / quotations (single tables with a
 * sandbox_id column) are checked by the model guard.
 *
 * Every constraint is explicitly named: auto-generated names for these long
 * table names would exceed MySQL's 64-character identifier limit.
 */
return new class extends Migration
{
    private const TABLES = [
        'sandbox_procurement_receipts', 'sandbox_purchase_order_lines', 'sandbox_purchase_orders',
        'sandbox_sales_order_procurements', 'sandbox_quotation_supplier_availability_requests',
    ];

    public function up(): void
    {
        Schema::create('sandbox_quotation_supplier_availability_requests', function (Blueprint $table) {
            $table->id();
            $this->sandboxKey($table, 'sbx_qsar');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('quotation_id')->nullable();
            $table->unsignedBigInteger('quotation_line_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('non_stock_item_id')->nullable();
            $table->decimal('requested_quantity', 19, 4);
            $table->decimal('confirmed_quantity', 19, 4)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('resolved_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_qsar_sbx_id_uq');
            $table->index(['sandbox_id', 'organization_id', 'quotation_line_id', 'status'], 'sbx_qsar_line_idx');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_line_id', 'status'], 'sbx_qsar_so_line_idx');
            $table->index(['sandbox_id', 'organization_id', 'supplier_id', 'status'], 'sbx_qsar_supplier_idx');
            $this->users($table, 'sbx_qsar', ['requested_by_user_id', 'resolved_by_user_id']);
            $this->fk($table, 'sbx_qsar_store_fk', ['organization_id', 'store_id'], 'stores');
            $this->fk($table, 'sbx_qsar_quote_fk', ['organization_id', 'quotation_id'], 'quotations');
            $this->fk($table, 'sbx_qsar_qline_fk', ['organization_id', 'quotation_line_id'], 'quotation_lines');
            $this->fk($table, 'sbx_qsar_supp_fk', ['organization_id', 'supplier_id'], 'suppliers');
            $this->fk($table, 'sbx_qsar_var_fk', ['organization_id', 'product_variant_id'], 'product_variants');
            $this->fk($table, 'sbx_qsar_nsi_fk', ['organization_id', 'non_stock_item_id'], 'non_stock_items');
            $this->fk($table, 'sbx_qsar_so_fk', ['organization_id', 'sales_order_id'], 'sales_orders');
            $this->fk($table, 'sbx_qsar_sol_fk', ['organization_id', 'sales_order_line_id'], 'sales_order_lines');
        });

        Schema::create('sandbox_sales_order_procurements', function (Blueprint $table) {
            $table->id();
            $this->sandboxKey($table, 'sbx_proc');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->string('procurement_number', 64);
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 19, 4);
            $table->string('status', 32)->default('pending_supplier');
            $table->string('supplier_availability_status', 32)->default('pending_confirmation');
            $table->string('supplier_reference')->nullable();
            $table->date('expected_at')->nullable();
            $table->dateTime('ordered_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->unsignedBigInteger('receiving_warehouse_id')->nullable();
            // Mirrors the Production column; always NULL in Test (transfer requests are not sandboxed).
            $table->unsignedBigInteger('transfer_request_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->decimal('supplier_unit_cost', 19, 4)->nullable();
            $table->string('supplier_invoice_reference')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->unsignedBigInteger('ordered_by_user_id')->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->unsignedBigInteger('source_quotation_id')->nullable();
            $table->unsignedBigInteger('source_quotation_line_id')->nullable();
            $table->unsignedBigInteger('source_supplier_request_id')->nullable();
            $table->decimal('received_quantity', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_proc_sbx_id_uq');
            $table->unique(['organization_id', 'procurement_number'], 'sbx_proc_number_uq');
            $table->unique(['sandbox_id', 'organization_id', 'source_supplier_request_id'], 'sbx_proc_src_req_uq');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_id'], 'sbx_proc_order_idx');
            $table->index(['sandbox_id', 'organization_id', 'status'], 'sbx_proc_status_idx');
            $table->index(['sandbox_id', 'organization_id', 'supplier_id', 'status'], 'sbx_proc_supplier_idx');
            $this->users($table, 'sbx_proc', ['created_by_user_id', 'confirmed_by_user_id', 'ordered_by_user_id', 'received_by_user_id', 'cancelled_by_user_id']);
            $this->fk($table, 'sbx_proc_store_fk', ['organization_id', 'store_id'], 'stores');
            $this->fk($table, 'sbx_proc_supp_fk', ['organization_id', 'supplier_id'], 'suppliers');
            $this->fk($table, 'sbx_proc_so_fk', ['organization_id', 'sales_order_id'], 'sales_orders');
            $this->fk($table, 'sbx_proc_sol_fk', ['organization_id', 'sales_order_line_id'], 'sales_order_lines');
            $this->fk($table, 'sbx_proc_var_fk', ['organization_id', 'product_variant_id'], 'product_variants');
            $this->fk($table, 'sbx_proc_wh_fk', ['organization_id', 'receiving_warehouse_id'], 'warehouses');
            $this->fk($table, 'sbx_proc_quote_fk', ['organization_id', 'source_quotation_id'], 'quotations');
            $this->fk($table, 'sbx_proc_qline_fk', ['organization_id', 'source_quotation_line_id'], 'quotation_lines');
            $table->foreign(['sandbox_id', 'source_supplier_request_id'], 'sbx_proc_req_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_quotation_supplier_availability_requests')->restrictOnDelete();
        });

        Schema::create('sandbox_purchase_orders', function (Blueprint $table) {
            $table->id();
            $this->sandboxKey($table, 'sbx_po');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('purchase_order_number', 64);
            $table->string('status', 32);
            $table->char('currency_code', 3);
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('client_operation_id')->nullable();
            $table->dateTime('ordered_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('ordered_by_user_id')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_po_sbx_id_uq');
            $table->unique(['organization_id', 'purchase_order_number'], 'sbx_po_number_uq');
            $table->unique(['sandbox_id', 'organization_id', 'client_operation_id'], 'sbx_po_client_op_uq');
            $table->index(['sandbox_id', 'organization_id', 'status'], 'sbx_po_status_idx');
            $table->index(['sandbox_id', 'organization_id', 'supplier_id', 'status'], 'sbx_po_supplier_idx');
            $this->users($table, 'sbx_po', ['created_by_user_id', 'ordered_by_user_id', 'cancelled_by_user_id']);
            $this->fk($table, 'sbx_po_store_fk', ['organization_id', 'store_id'], 'stores');
            $this->fk($table, 'sbx_po_supp_fk', ['organization_id', 'supplier_id'], 'suppliers');
        });

        Schema::create('sandbox_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $this->sandboxKey($table, 'sbx_pol');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('sales_order_procurement_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedInteger('position');
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4)->nullable();
            $table->decimal('received_quantity', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_pol_sbx_id_uq');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_id'], 'sbx_pol_order_idx');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_procurement_id'], 'sbx_pol_proc_idx');
            $table->foreign(['sandbox_id', 'purchase_order_id'], 'sbx_pol_po_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_purchase_orders')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'sales_order_procurement_id'], 'sbx_pol_proc_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_sales_order_procurements')->restrictOnDelete();
            $this->fk($table, 'sbx_pol_so_fk', ['organization_id', 'sales_order_id'], 'sales_orders');
            $this->fk($table, 'sbx_pol_sol_fk', ['organization_id', 'sales_order_line_id'], 'sales_order_lines');
            $this->fk($table, 'sbx_pol_var_fk', ['organization_id', 'product_variant_id'], 'product_variants');
        });

        Schema::create('sandbox_procurement_receipts', function (Blueprint $table) {
            $table->id();
            $this->sandboxKey($table, 'sbx_rcpt');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('sales_order_procurement_id');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity', 19, 4);
            $table->date('received_on');
            $table->string('supplier_delivery_reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('inventory_movement_id');
            $table->unsignedBigInteger('inventory_reservation_id');
            $table->unsignedBigInteger('sales_order_inventory_allocation_id')->nullable();
            $table->uuid('client_operation_id')->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_rcpt_sbx_id_uq');
            $table->unique(['sandbox_id', 'organization_id', 'client_operation_id'], 'sbx_rcpt_client_op_uq');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_procurement_id'], 'sbx_rcpt_proc_idx');
            $table->index(['sandbox_id', 'organization_id', 'purchase_order_line_id'], 'sbx_rcpt_pol_idx');
            $this->users($table, 'sbx_rcpt', ['received_by_user_id']);
            $this->fk($table, 'sbx_rcpt_wh_fk', ['organization_id', 'warehouse_id'], 'warehouses');
            $table->foreign(['sandbox_id', 'sales_order_procurement_id'], 'sbx_rcpt_proc_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_sales_order_procurements')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'purchase_order_line_id'], 'sbx_rcpt_pol_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_purchase_order_lines')->restrictOnDelete();
            // The receipt's inventory effects live on the Test inventory ledger only.
            $table->foreign(['sandbox_id', 'inventory_movement_id'], 'sbx_rcpt_mov_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_inventory_movements')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'inventory_reservation_id'], 'sbx_rcpt_res_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_inventory_reservations')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'sales_order_inventory_allocation_id'], 'sbx_rcpt_alloc_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_sales_order_inventory_allocations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Dropping these tables would silently erase Test purchase orders and
        // receipts whose stock effects remain on the Test inventory ledger.
        foreach (array_reverse(self::TABLES) as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove the Test procurement ledger while Test procurement records exist.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function sandboxKey(Blueprint $table, string $prefix): void
    {
        $table->unsignedBigInteger('sandbox_id');
        $table->foreign('sandbox_id', $prefix.'_sandbox_fk')->references('id')->on('sandboxes')->restrictOnDelete();
    }

    /** @param list<string> $columns */
    private function users(Blueprint $table, string $prefix, array $columns): void
    {
        foreach ($columns as $i => $column) {
            $table->foreign($column, $prefix.'_u'.($i + 1).'_fk')->references('id')->on('users')->nullOnDelete();
        }
    }

    /** Shared master data / single-table roots, referenced through the composite (organization_id, id) key. */
    private function fk(Blueprint $table, string $name, array $columns, string $on): void
    {
        $table->foreign($columns, $name)->references(['organization_id', 'id'])->on($on)->restrictOnDelete();
    }
};
