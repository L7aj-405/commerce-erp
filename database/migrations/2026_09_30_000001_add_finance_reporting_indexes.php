<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->index(['organization_id', 'status', 'sale_date'], 'sales_ord_org_status_date_idx');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['organization_id', 'status', 'invoice_date'], 'invoice_org_status_date_idx');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['organization_id', 'status', 'payment_date'], 'payment_org_status_date_idx');
        });
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->index(['organization_id', 'status', 'credit_note_date'], 'credit_note_org_status_date_idx');
        });
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->index(['organization_id', 'status', 'refund_date'], 'pay_refund_org_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', fn (Blueprint $table) => $table->dropIndex('pay_refund_org_status_date_idx'));
        Schema::table('credit_notes', fn (Blueprint $table) => $table->dropIndex('credit_note_org_status_date_idx'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payment_org_status_date_idx'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropIndex('invoice_org_status_date_idx'));
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropIndex('sales_ord_org_status_date_idx'));
    }
};
