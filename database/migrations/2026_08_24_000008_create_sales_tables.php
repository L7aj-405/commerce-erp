<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('display_name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('tax_identifier', 128)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'cust_org_id_unique');
            $table->index(['organization_id', 'status'], 'cust_org_status_idx');
            $table->index(['organization_id', 'display_name'], 'cust_org_name_idx');
            $table->index(['organization_id', 'company_name'], 'cust_org_company_idx');
            $table->index(['organization_id', 'email'], 'cust_org_email_idx');
            $table->index(['organization_id', 'phone'], 'cust_org_phone_idx');
        });

        Schema::create('sales_order_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_number', 64);
            $table->string('source', 24)->default('manual');
            $table->string('status', 24)->default('draft');
            $table->string('fulfillment_status', 32)->default('unfulfilled');
            $table->string('payment_status', 32)->default('unpaid');
            $table->char('currency_code', 3);
            $table->dateTime('ordered_at');
            $table->date('sale_date');
            $table->string('customer_name')->nullable();
            $table->string('customer_company')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 64)->nullable();
            $table->decimal('subtotal_excl_tax', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total_incl_tax', 19, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'order_number'], 'sales_ord_number_unique');
            $table->unique(['organization_id', 'id'], 'sales_ord_org_id_unique');
            $table->index(['organization_id', 'store_id', 'status'], 'sales_ord_store_status_idx');
            $table->index(['organization_id', 'store_id', 'sale_date'], 'sales_ord_store_date_idx');
            $table->index(['organization_id', 'customer_id'], 'sales_ord_customer_idx');
            $table->index(['organization_id', 'created_at'], 'sales_ord_created_idx');
            $table->foreign(['organization_id', 'store_id'], 'sales_ord_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'customer_id'], 'sales_ord_customer_fk')
                ->references(['organization_id', 'id'])->on('customers')->restrictOnDelete();
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->string('line_type', 24);
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('reference')->nullable();
            $table->string('unit_label')->nullable();
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price_excl_tax', 19, 4);
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('discount_type', 24)->default('none');
            $table->decimal('discount_value', 19, 4)->default(0);
            $table->decimal('subtotal_excl_tax', 19, 4);
            $table->decimal('discount_amount', 19, 4);
            $table->decimal('taxable_amount', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('total_incl_tax', 19, 4);
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'sales_line_org_id_unique');
            $table->unique(['organization_id', 'sales_order_id', 'position'], 'sales_line_position_unique');
            $table->index(['organization_id', 'product_variant_id'], 'sales_line_variant_idx');
            $table->foreign(['organization_id', 'sales_order_id'], 'sales_line_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'sales_line_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('sales_order_inventory_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity', 19, 4);
            $table->unsignedBigInteger('inventory_reservation_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'sales_alloc_org_id_unique');
            $table->unique(['organization_id', 'sales_order_line_id', 'warehouse_id'], 'sales_alloc_line_wh_unique');
            $table->index(['organization_id', 'warehouse_id'], 'sales_alloc_wh_idx');
            $table->unique(['organization_id', 'inventory_reservation_id'], 'sales_alloc_res_unique');
            $table->foreign(['organization_id', 'sales_order_line_id'], 'sales_alloc_line_fk')
                ->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'sales_alloc_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['organization_id', 'inventory_reservation_id'], 'sales_alloc_res_fk')
                ->references(['organization_id', 'id'])->on('inventory_reservations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_inventory_allocations');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('sales_order_sequences');
        Schema::dropIfExists('customers');
    }
};
