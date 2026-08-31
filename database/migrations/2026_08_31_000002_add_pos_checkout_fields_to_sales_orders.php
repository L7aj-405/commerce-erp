<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('pos_global_discount_type', 24)->default('none')->after('pos_warehouse_id');
            $table->decimal('pos_global_discount_value', 19, 4)->default(0)->after('pos_global_discount_type');
            $table->string('pos_fulfillment_mode', 24)->nullable()->after('pos_global_discount_value');
            $table->decimal('pos_shipping_fee', 19, 4)->default(0)->after('pos_fulfillment_mode');
            $table->text('pos_delivery_address')->nullable()->after('pos_shipping_fee');
            $table->string('pos_delivery_phone', 64)->nullable()->after('pos_delivery_address');
            $table->text('pos_delivery_notes')->nullable()->after('pos_delivery_phone');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn([
                'pos_global_discount_type',
                'pos_global_discount_value',
                'pos_fulfillment_mode',
                'pos_shipping_fee',
                'pos_delivery_address',
                'pos_delivery_phone',
                'pos_delivery_notes',
            ]);
        });
    }
};
