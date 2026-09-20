<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refund_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->string('refund_number', 64);
            $table->string('method', 32);
            $table->string('status', 24)->default('posted');
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('refund_date');
            $table->text('reason');
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'pay_refund_org_id_unique');
            $table->unique(['organization_id', 'refund_number'], 'pay_refund_org_number_unique');
            $table->unique(['organization_id', 'payment_id', 'sales_order_id'], 'pay_refund_payment_order_unique');
            $table->index(['organization_id', 'store_id', 'refund_date'], 'pay_refund_store_date_idx');
            $table->index(['organization_id', 'financial_account_id'], 'pay_refund_account_idx');

            $table->foreign(['organization_id', 'store_id'], 'pay_refund_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'payment_id'], 'pay_refund_payment_fk')
                ->references(['organization_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'pay_refund_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_account_id'], 'pay_refund_account_fk')
                ->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_refund_sequences');
    }
};
