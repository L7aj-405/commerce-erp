<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_addenda', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedInteger('sequence');
            $table->uuid('client_operation_id');
            $table->char('operation_hash', 64);
            $table->decimal('before_total', 19, 4);
            $table->decimal('added_total', 19, 4)->default(0);
            $table->decimal('after_total', 19, 4)->default(0);
            $table->dateTime('fulfilled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'sales_addendum_org_id_unique');
            $table->unique(['organization_id', 'sales_order_id', 'sequence'], 'sales_addendum_sequence_unique');
            $table->unique(['organization_id', 'sales_order_id', 'client_operation_id'], 'sales_addendum_operation_unique');
            $table->foreign(['organization_id', 'store_id'], 'sales_addendum_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'sales_addendum_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_addendum_id')->nullable()->after('sales_order_id');
            $table->index(['organization_id', 'sales_order_addendum_id'], 'sales_line_addendum_idx');
            $table->foreign(['organization_id', 'sales_order_addendum_id'], 'sales_line_addendum_fk')
                ->references(['organization_id', 'id'])->on('sales_order_addenda')->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_addendum_id')->nullable()->after('sales_order_revision_id');
            $table->index(['organization_id', 'sales_order_addendum_id'], 'invoice_addendum_idx');
            $table->foreign(['organization_id', 'sales_order_addendum_id'], 'invoice_addendum_fk')
                ->references(['organization_id', 'id'])->on('sales_order_addenda')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoice_addendum_fk');
            $table->dropIndex('invoice_addendum_idx');
            $table->dropColumn('sales_order_addendum_id');
        });
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropForeign('sales_line_addendum_fk');
            $table->dropIndex('sales_line_addendum_idx');
            $table->dropColumn('sales_order_addendum_id');
        });
        Schema::dropIfExists('sales_order_addenda');
    }
};
