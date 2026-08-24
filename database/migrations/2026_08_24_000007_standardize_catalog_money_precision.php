<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('purchase_price', 19, 4)->nullable()->change();
            $table->decimal('default_sale_price', 19, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('purchase_price', 15, 4)->nullable()->change();
            $table->decimal('default_sale_price', 15, 4)->change();
        });
    }
};
