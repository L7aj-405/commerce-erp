<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-quotation procurement P2: partial supplier receipts.
 *
 * - sales_order_procurements.received_quantity / purchase_order_lines.received_quantity:
 *   how much of the requirement / ordered line physically arrived. Existing
 *   received (or completed) requirements are backfilled as fully received.
 *
 * - procurement_receipts: one row per physical receipt (ReceiveProcurementAction)
 *   — quantity, warehouse, date, supplier delivery reference, the inventory
 *   movement it created and the reservation earmarking it for the Sales Order
 *   line. It is the receipt log of the existing procurement domain, not a second
 *   goods-receipt domain. The movement / reservation / allocation ids are kept
 *   without foreign keys so the stock resets keep their own order.
 *
 * Additive only; explicit short constraint names; tenant FKs RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_procurements', function (Blueprint $table) {
            $table->decimal('received_quantity', 19, 4)->default(0)->after('quantity');
        });
        DB::table('sales_order_procurements')->whereIn('status', ['received', 'completed'])
            ->update(['received_quantity' => DB::raw('quantity')]);

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->decimal('received_quantity', 19, 4)->default(0)->after('quantity');
        });

        Schema::create('procurement_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('sales_order_procurement_id');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity', 19, 4);
            $table->date('received_on');
            $table->string('supplier_delivery_reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('inventory_movement_id');
            // The reservation earmarking the received goods for the Sales Order line;
            // the allocation is set once the reservation belongs to one (at once on a
            // confirmed order, at confirmation for a draft).
            $table->unsignedBigInteger('inventory_reservation_id');
            $table->unsignedBigInteger('sales_order_inventory_allocation_id')->nullable();
            $table->uuid('client_operation_id')->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'proc_rcpt_org_id_unique');
            $table->unique(['organization_id', 'client_operation_id'], 'proc_rcpt_client_op_unique');
            $table->index(['organization_id', 'sales_order_procurement_id'], 'proc_rcpt_procurement_idx');
            $table->index(['organization_id', 'purchase_order_line_id'], 'proc_rcpt_po_line_idx');
            $table->index(['organization_id', 'inventory_reservation_id'], 'proc_rcpt_reservation_idx');

            $table->foreign('organization_id', 'proc_rcpt_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'sales_order_procurement_id'], 'proc_rcpt_procurement_fk')
                ->references(['organization_id', 'id'])->on('sales_order_procurements')->restrictOnDelete();
            $table->foreign(['organization_id', 'purchase_order_line_id'], 'proc_rcpt_po_line_fk')
                ->references(['organization_id', 'id'])->on('purchase_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'proc_rcpt_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign('received_by_user_id', 'proc_rcpt_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_receipts');
        Schema::table('purchase_order_lines', fn (Blueprint $table) => $table->dropColumn('received_quantity'));
        Schema::table('sales_order_procurements', fn (Blueprint $table) => $table->dropColumn('received_quantity'));
    }
};
