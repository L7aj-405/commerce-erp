<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_return_sequences')) {
            Schema::create('customer_return_sequences', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id');
                $table->unsignedSmallInteger('year');
                $table->unsignedBigInteger('next_number')->default(1);
                $table->timestamps();
                $table->primary(['organization_id', 'year']);
                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('credit_note_sequences')) {
            Schema::create('credit_note_sequences', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id');
                $table->unsignedSmallInteger('year');
                $table->unsignedBigInteger('next_number')->default(1);
                $table->timestamps();
                $table->primary(['organization_id', 'year']);
                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('customer_returns')) {
            Schema::create('customer_returns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('sales_order_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->string('return_number', 64);
                $table->uuid('client_operation_id');
                $table->string('status', 24)->default('draft');
                $table->string('disposition', 24)->default('restock');
                $table->text('reason');
                $table->char('currency_code', 3);
                $table->decimal('subtotal_excl_tax', 19, 4)->default(0);
                $table->decimal('discount_total', 19, 4)->default(0);
                $table->decimal('tax_total', 19, 4)->default(0);
                $table->decimal('total_incl_tax', 19, 4)->default(0);
                $table->json('policy_snapshot');
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('received_at')->nullable();
                $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('cancelled_at')->nullable();
                $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'id'], 'cust_return_org_id_unique');
                $table->unique(['organization_id', 'return_number'], 'cust_return_number_unique');
                $table->unique(['organization_id', 'store_id', 'client_operation_id'], 'cust_return_operation_unique');
                $table->index(['organization_id', 'store_id', 'status'], 'cust_return_store_status_idx');
                $table->foreign(['organization_id', 'store_id'], 'cust_return_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
                $table->foreign(['organization_id', 'sales_order_id'], 'cust_return_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
                $table->foreign(['organization_id', 'warehouse_id'], 'cust_return_warehouse_fk')->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('customer_return_lines')) {
            Schema::create('customer_return_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('customer_return_id');
                $table->unsignedBigInteger('sales_order_line_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->unsignedInteger('position');
                $table->string('product_name');
                $table->string('variant_name')->nullable();
                $table->string('sku')->nullable();
                $table->string('reference')->nullable();
                $table->string('unit_label')->nullable();
                $table->decimal('quantity', 19, 4);
                $table->decimal('unit_price_excl_tax', 19, 4);
                $table->decimal('unit_price_incl_tax', 19, 4);
                $table->decimal('subtotal_excl_tax', 19, 4);
                $table->decimal('discount_amount', 19, 4);
                $table->decimal('taxable_amount', 19, 4);
                $table->string('tax_name')->nullable();
                $table->decimal('tax_rate', 7, 4)->default(0);
                $table->decimal('tax_amount', 19, 4);
                $table->decimal('total_incl_tax', 19, 4);
                $table->timestamps();
                $table->unique(['organization_id', 'id'], 'cust_return_line_org_id_unique');
                $table->unique(['organization_id', 'customer_return_id', 'sales_order_line_id'], 'cust_return_source_unique');
                $table->foreign(['organization_id', 'customer_return_id'], 'cust_return_line_return_fk')->references(['organization_id', 'id'])->on('customer_returns')->restrictOnDelete();
                $table->foreign(['organization_id', 'sales_order_line_id'], 'cust_return_line_sales_fk')->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
                $table->foreign(['organization_id', 'product_variant_id'], 'cust_return_line_variant_fk')->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('credit_notes')) {
            Schema::create('credit_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('customer_return_id');
                $table->unsignedBigInteger('invoice_id');
                $table->unsignedBigInteger('sales_order_id');
                $table->string('credit_note_number', 64)->nullable();
                $table->string('status', 24)->default('draft');
                $table->date('credit_note_date');
                $table->char('currency_code', 3);
                $table->decimal('subtotal_excl_tax', 19, 4);
                $table->decimal('discount_total', 19, 4);
                $table->decimal('tax_total', 19, 4);
                $table->decimal('total_incl_tax', 19, 4);
                $table->text('reason');
                $table->json('seller_snapshot');
                $table->json('customer_snapshot');
                $table->string('template_version', 32)->default('v1');
                $table->dateTime('issued_at')->nullable();
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['organization_id', 'id'], 'credit_note_org_id_unique');
                $table->unique(['organization_id', 'credit_note_number'], 'credit_note_number_unique');
                $table->unique(['organization_id', 'customer_return_id', 'invoice_id'], 'credit_note_return_invoice_unique');
                $table->index(['organization_id', 'store_id', 'status'], 'credit_note_store_status_idx');
                $table->foreign(['organization_id', 'store_id'], 'credit_note_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
                $table->foreign(['organization_id', 'customer_return_id'], 'credit_note_return_fk')->references(['organization_id', 'id'])->on('customer_returns')->restrictOnDelete();
                $table->foreign(['organization_id', 'invoice_id'], 'credit_note_invoice_fk')->references(['organization_id', 'id'])->on('invoices')->restrictOnDelete();
                $table->foreign(['organization_id', 'sales_order_id'], 'credit_note_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('credit_note_lines')) {
            Schema::create('credit_note_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('credit_note_id');
                $table->unsignedBigInteger('customer_return_line_id');
                $table->unsignedInteger('position');
                $table->string('description');
                $table->string('reference')->nullable();
                $table->string('unit_label')->nullable();
                $table->decimal('quantity', 19, 4);
                $table->decimal('unit_price_excl_tax', 19, 4);
                $table->decimal('unit_price_incl_tax', 19, 4);
                $table->decimal('subtotal_excl_tax', 19, 4);
                $table->decimal('discount_amount', 19, 4);
                $table->decimal('taxable_amount', 19, 4);
                $table->string('tax_name')->nullable();
                $table->decimal('tax_rate', 7, 4)->default(0);
                $table->decimal('tax_amount', 19, 4);
                $table->decimal('total_incl_tax', 19, 4);
                $table->timestamps();
                $table->unique(['organization_id', 'id'], 'credit_note_line_org_id_unique');
                $table->unique(['credit_note_id', 'customer_return_line_id'], 'credit_note_return_line_unique');
                $table->foreign(['organization_id', 'credit_note_id'], 'credit_note_line_note_fk')->references(['organization_id', 'id'])->on('credit_notes')->restrictOnDelete();
                $table->foreign(['organization_id', 'customer_return_line_id'], 'credit_note_line_return_fk')->references(['organization_id', 'id'])->on('customer_return_lines')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('payment_refunds', 'customer_return_id')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_return_id')->nullable()->after('sales_order_id');
            });
        }

        if (! Schema::hasColumn('payment_refunds', 'client_operation_id')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->uuid('client_operation_id')->nullable()->after('customer_return_id');
            });
        }

        // MySQL uses the legacy unique index to support pay_refund_payment_fk.
        // Add its non-unique replacement before removing the uniqueness rule.
        if (! $this->indexExists('payment_refunds', 'pay_refund_payment_idx')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->index(['organization_id', 'payment_id'], 'pay_refund_payment_idx');
            });
        }

        if ($this->indexExists('payment_refunds', 'pay_refund_payment_order_unique')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->dropUnique('pay_refund_payment_order_unique');
            });
        }

        if (! $this->indexExists('payment_refunds', 'pay_refund_return_payment_idx')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->index(['organization_id', 'customer_return_id', 'payment_id'], 'pay_refund_return_payment_idx');
            });
        }

        if (! $this->indexExists('payment_refunds', 'pay_refund_operation_unique')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->unique(['organization_id', 'store_id', 'client_operation_id'], 'pay_refund_operation_unique');
            });
        }

        if (! $this->foreignKeyExists('payment_refunds', 'pay_refund_return_fk')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->foreign(['organization_id', 'customer_return_id'], 'pay_refund_return_fk')->references(['organization_id', 'id'])->on('customer_returns')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropForeign('pay_refund_return_fk');
            $table->dropIndex('pay_refund_return_payment_idx');
            $table->dropUnique('pay_refund_operation_unique');
            $table->dropColumn(['customer_return_id', 'client_operation_id']);
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->unique(['organization_id', 'payment_id', 'sales_order_id'], 'pay_refund_payment_order_unique');
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropIndex('pay_refund_payment_idx');
        });

        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('customer_return_lines');
        Schema::dropIfExists('customer_returns');
        Schema::dropIfExists('credit_note_sequences');
        Schema::dropIfExists('customer_return_sequences');
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $index);
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        return collect(Schema::getForeignKeys($table))->contains('name', $foreignKey);
    }
};
