<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_warehouse_id')->nullable()->after('pos_checkout_hash');
            $table->dateTime('pos_held_at')->nullable()->after('fulfilled_at');

            $table->index(['organization_id', 'store_id', 'source', 'status', 'pos_held_at'], 'sales_ord_pos_state_idx');
            $table->foreign('pos_warehouse_id', 'sales_ord_pos_warehouse_fk')
                ->references('id')->on('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign('sales_ord_pos_warehouse_fk');
            $table->dropIndex('sales_ord_pos_state_idx');
            $table->dropColumn(['pos_warehouse_id', 'pos_held_at']);
        });
    }
};
