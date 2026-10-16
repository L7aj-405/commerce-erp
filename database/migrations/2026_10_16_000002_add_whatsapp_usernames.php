<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_contacts', function (Blueprint $table) {
            $table->string('whatsapp_username', 35)->nullable()->after('whatsapp');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('whatsapp_username', 35)->nullable()->after('phone');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('customer_whatsapp_username', 35)->nullable()->after('customer_phone');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('customer_whatsapp_username', 35)->nullable()->after('customer_phone');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('customer_whatsapp_username', 35)->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('customer_whatsapp_username'));
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn('customer_whatsapp_username'));
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('customer_whatsapp_username'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('whatsapp_username'));
        Schema::table('organization_contacts', fn (Blueprint $table) => $table->dropColumn('whatsapp_username'));
    }
};
