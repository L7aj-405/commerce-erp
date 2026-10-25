<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W4.5: Sales Order receivables attention.
 *
 * Real receivables live on confirmed Sales Orders (an invoice can only be
 * issued once its order is fully paid). The ERP had no payment due concept
 * anywhere (no terms on orders, customers or finance), so:
 *
 * - sales_orders.due_date: business-local DATE, stamped once at confirmation
 *   (ConfirmSalesOrderAction) as sale_date + payments.sales_order_payment_term_days
 *   (default 7); a commercial revision re-confirming the order keeps it.
 *   Already confirmed orders are backfilled the same way in one set-based
 *   statement. Index for the detector's candidate scan.
 * - follow_ups.sales_order_id: explicit order link (single column, SET NULL),
 *   validated by SaveFollowUpAction (exclusive with quotation/invoice links).
 * - attention_items.sales_order_id: convenience FK for order episodes.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('sale_date');
            $table->index(['organization_id', 'status', 'due_date'], 'sales_ord_status_due_idx');
        });

        $days = max(0, (int) config('payments.sales_order_payment_term_days', 7));
        $dueDate = match (DB::connection()->getDriverName()) {
            'sqlite' => DB::raw("date(sale_date, '+{$days} days')"),
            default => DB::raw("DATE_ADD(sale_date, INTERVAL {$days} DAY)"), // mysql / mariadb
        };
        DB::table('sales_orders')->where('status', 'confirmed')->whereNull('due_date')->update(['due_date' => $dueDate]);

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('invoice_id')->constrained('sales_orders')->nullOnDelete();
            $table->index(['organization_id', 'sales_order_id'], 'follow_up_sales_order_idx');
        });

        Schema::table('attention_items', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('invoice_id')->constrained('sales_orders')->nullOnDelete();
            $table->index(['organization_id', 'sales_order_id'], 'attention_sales_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attention_items', function (Blueprint $table) {
            $table->dropIndex('attention_sales_order_idx');
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_up_sales_order_idx');
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_ord_status_due_idx');
            $table->dropColumn('due_date');
        });
    }
};
