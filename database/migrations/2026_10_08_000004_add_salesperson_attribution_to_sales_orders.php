<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('salesperson_id')->nullable()->after('customer_id')
                ->constrained('users', indexName: 'sales_ord_salesperson_fk')->nullOnDelete();
            $table->string('salesperson_name_snapshot')->nullable()->after('salesperson_id');
            $table->index(
                ['organization_id', 'salesperson_id', 'sale_date'],
                'sales_ord_salesperson_date_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_ord_salesperson_date_idx');
            $table->dropForeign('sales_ord_salesperson_fk');
            $table->dropColumn(['salesperson_id', 'salesperson_name_snapshot']);
        });
    }
};
