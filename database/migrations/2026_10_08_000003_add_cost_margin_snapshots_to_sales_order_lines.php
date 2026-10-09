<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->decimal('purchase_price_snapshot', 19, 4)->nullable()->after('total_incl_tax');
            $table->decimal('cost_total_snapshot', 19, 4)->nullable()->after('purchase_price_snapshot');
            $table->decimal('margin_amount_snapshot', 19, 4)->nullable()->after('cost_total_snapshot');
            $table->decimal('margin_rate_snapshot', 19, 4)->nullable()->after('margin_amount_snapshot');
            $table->string('cost_status', 24)->nullable()->after('margin_rate_snapshot');
            $table->index(['organization_id', 'cost_status'], 'sol_org_cost_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropIndex('sol_org_cost_status_idx');
            $table->dropColumn([
                'purchase_price_snapshot',
                'cost_total_snapshot',
                'margin_amount_snapshot',
                'margin_rate_snapshot',
                'cost_status',
            ]);
        });
    }
};
