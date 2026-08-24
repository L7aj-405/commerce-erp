<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->uuid('client_operation_id')->nullable()->after('source');
            $table->unique(
                ['organization_id', 'store_id', 'client_operation_id'],
                'sales_ord_store_operation_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_ord_store_operation_unique');
            $table->dropColumn('client_operation_id');
        });
    }
};
