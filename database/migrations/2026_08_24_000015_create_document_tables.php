<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('delivery_note_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->string('status', 24)->default('draft');
            $table->char('currency_code', 3);
            $table->date('invoice_date');
            $table->string('customer_name')->nullable();
            $table->string('customer_company')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 64)->nullable();
            $table->string('customer_tax_identifier', 128)->nullable();
            $table->text('billing_address')->nullable();
            $table->decimal('subtotal_excl_tax', 19, 4);
            $table->decimal('discount_total', 19, 4);
            $table->decimal('tax_total', 19, 4);
            $table->decimal('total_incl_tax', 19, 4);
            $table->text('notes')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'invoice_org_id_unique');
            $table->unique(['organization_id', 'invoice_number'], 'invoice_org_number_unique');
            $table->index(['organization_id', 'store_id', 'status'], 'invoice_store_status_idx');
            $table->index(['organization_id', 'store_id', 'invoice_date'], 'invoice_store_date_idx');
            $table->index(['organization_id', 'sales_order_id'], 'invoice_order_idx');
            $table->foreign(['organization_id', 'store_id'], 'invoice_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'invoice_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'customer_id'], 'invoice_customer_fk')->references(['organization_id', 'id'])->on('customers')->restrictOnDelete();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedInteger('position');
            $table->string('line_type', 24);
            $table->string('description');
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('reference')->nullable();
            $table->string('unit_label')->nullable();
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price_excl_tax', 19, 4);
            $table->string('discount_type', 24)->default('none');
            $table->decimal('discount_value', 19, 4)->default(0);
            $table->decimal('subtotal_excl_tax', 19, 4);
            $table->decimal('discount_amount', 19, 4);
            $table->decimal('taxable_amount', 19, 4);
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('total_incl_tax', 19, 4);
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'invoice_line_org_id_unique');
            $table->unique(['organization_id', 'invoice_id', 'position'], 'invoice_line_position_unique');
            $table->unique(['invoice_id', 'sales_order_line_id'], 'invoice_source_line_unique');
            $table->foreign(['organization_id', 'invoice_id'], 'invoice_line_invoice_fk')->references(['organization_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_line_id'], 'invoice_line_sales_fk')->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'invoice_line_variant_fk')->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->string('delivery_note_number', 64)->nullable();
            $table->string('status', 24)->default('draft');
            $table->date('delivery_date');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_company')->nullable();
            $table->string('recipient_phone', 64)->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'delivery_note_org_id_unique');
            $table->unique(['organization_id', 'delivery_note_number'], 'delivery_note_org_number_unique');
            $table->index(['organization_id', 'store_id', 'status'], 'delivery_note_store_status_idx');
            $table->index(['organization_id', 'store_id', 'delivery_date'], 'delivery_note_store_date_idx');
            $table->index(['organization_id', 'sales_order_id'], 'delivery_note_order_idx');
            $table->foreign(['organization_id', 'store_id'], 'delivery_note_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'delivery_note_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('delivery_note_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedInteger('position');
            $table->string('line_type', 24);
            $table->string('description');
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('reference')->nullable();
            $table->string('unit_label')->nullable();
            $table->decimal('quantity', 19, 4);
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'delivery_line_org_id_unique');
            $table->unique(['organization_id', 'delivery_note_id', 'position'], 'delivery_line_position_unique');
            $table->unique(['delivery_note_id', 'sales_order_line_id'], 'delivery_source_line_unique');
            $table->foreign(['organization_id', 'delivery_note_id'], 'delivery_line_note_fk')->references(['organization_id', 'id'])->on('delivery_notes')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_line_id'], 'delivery_line_sales_fk')->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'delivery_line_variant_fk')->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('delivery_note_sequences');
        Schema::dropIfExists('invoice_sequences');
    }
};
