<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments domain — consolidated baseline (unchanged from create_payment_tables;
 * already in its final form).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('type', 32);
            $table->string('status', 24)->default('active');
            $table->char('currency_code', 3);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'fin_acct_org_id_unique');
            $table->unique(['organization_id', 'code'], 'fin_acct_org_code_unique');
            $table->index(['organization_id', 'status', 'type'], 'fin_acct_org_status_type_idx');
        });

        Schema::create('payment_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->string('payment_number', 64);
            $table->string('method', 32);
            $table->string('status', 24)->default('posted');
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->string('external_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_operation_id')->nullable();
            $table->unsignedSmallInteger('operation_sequence')->default(1);
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'payment_org_id_unique');
            $table->unique(['organization_id', 'payment_number'], 'payment_org_number_unique');
            $table->unique(['organization_id', 'store_id', 'client_operation_id', 'operation_sequence'], 'payment_store_operation_unique');
            $table->index(['organization_id', 'store_id', 'payment_date'], 'payment_store_date_idx');
            $table->index(['organization_id', 'store_id', 'status'], 'payment_store_status_idx');
            $table->index(['organization_id', 'financial_account_id'], 'payment_account_idx');
            $table->foreign(['organization_id', 'store_id'], 'payment_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_account_id'], 'payment_account_fk')
                ->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'pay_alloc_org_id_unique');
            $table->unique(['organization_id', 'payment_id', 'sales_order_id'], 'pay_alloc_payment_order_unique');
            $table->index(['organization_id', 'sales_order_id'], 'pay_alloc_order_idx');
            $table->foreign(['organization_id', 'payment_id'], 'pay_alloc_payment_fk')
                ->references(['organization_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'pay_alloc_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_sequences');
        Schema::dropIfExists('financial_accounts');
    }
};
