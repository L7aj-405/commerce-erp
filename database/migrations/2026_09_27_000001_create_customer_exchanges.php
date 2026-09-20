<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_exchange_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->primary(['organization_id', 'year']);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('customer_exchanges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('customer_return_id');
            $table->unsignedBigInteger('sales_order_addendum_id')->nullable();
            $table->string('exchange_number', 64);
            $table->uuid('client_operation_id');
            $table->char('operation_hash', 64);
            $table->string('status', 32)->default('awaiting_return_receipt');
            $table->string('settlement_status', 32)->default('pending');
            $table->json('replacement_items');
            $table->decimal('returned_total', 19, 4);
            $table->decimal('new_items_total', 19, 4)->default(0);
            $table->decimal('difference_amount', 19, 4)->default(0);
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('return_received_at')->nullable();
            $table->dateTime('replacement_fulfilled_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'customer_exchange_org_id_unique');
            $table->unique(['organization_id', 'exchange_number'], 'customer_exchange_number_unique');
            $table->unique(['organization_id', 'sales_order_id', 'client_operation_id'], 'customer_exchange_operation_unique');
            $table->unique(['organization_id', 'customer_return_id'], 'customer_exchange_return_unique');
            $table->unique(['organization_id', 'sales_order_addendum_id'], 'customer_exchange_addendum_unique');
            $table->index(['organization_id', 'store_id', 'status'], 'customer_exchange_store_status_idx');
            $table->foreign(['organization_id', 'store_id'], 'customer_exchange_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'customer_exchange_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'customer_return_id'], 'customer_exchange_return_fk')->references(['organization_id', 'id'])->on('customer_returns')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_addendum_id'], 'customer_exchange_addendum_fk')->references(['organization_id', 'id'])->on('sales_order_addenda')->restrictOnDelete();
        });

        Schema::create('customer_exchange_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('customer_exchange_id');
            $table->unsignedBigInteger('payment_id');
            $table->timestamps();
            $table->unique(['organization_id', 'customer_exchange_id', 'payment_id'], 'customer_exchange_payment_unique');
            $table->foreign(['organization_id', 'customer_exchange_id'], 'customer_exchange_payment_exchange_fk')->references(['organization_id', 'id'])->on('customer_exchanges')->restrictOnDelete();
            $table->foreign(['organization_id', 'payment_id'], 'customer_exchange_payment_payment_fk')->references(['organization_id', 'id'])->on('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_exchange_payments');
        Schema::dropIfExists('customer_exchanges');
        Schema::dropIfExists('customer_exchange_sequences');
    }
};
