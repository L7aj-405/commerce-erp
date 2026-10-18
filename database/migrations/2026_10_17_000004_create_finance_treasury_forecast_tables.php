<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_opening_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->date('effective_date');
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_open_org_id_uq');
            $table->unique(['organization_id', 'financial_account_id'], 'fin_open_org_account_uq');
            $table->index(['organization_id', 'financial_account_id', 'effective_date'], 'fin_open_org_account_date_idx');
            $table->foreign('organization_id', 'fin_open_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'faop_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'financial_account_id'], 'fin_open_account_fk')
                ->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
        });

        Schema::create('finance_expected_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('sales_order_id');
            $table->string('label')->nullable();
            $table->string('reference')->nullable();
            $table->date('expected_date');
            $table->decimal('expected_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('state', 16)->default('planned');
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_exp_org_id_uq');
            $table->index(['organization_id', 'expected_date'], 'fin_exp_org_date_idx');
            $table->index(['organization_id', 'store_id', 'expected_date'], 'fin_exp_org_store_date_idx');
            $table->index(['organization_id', 'sales_order_id'], 'fin_exp_org_order_idx');
            $table->index(['organization_id', 'state', 'expected_date'], 'fin_exp_org_state_date_idx');
            $table->foreign('organization_id', 'fin_exp_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('cancelled_by_user_id', 'fec_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id', 'fec_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'fin_exp_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'fin_exp_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });

        Schema::create('finance_expected_collection_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('expected_collection_id');
            $table->unsignedBigInteger('payment_allocation_id');
            $table->decimal('amount', 19, 4);
            $table->foreignId('matched_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_exm_org_id_uq');
            $table->unique(['organization_id', 'expected_collection_id', 'payment_allocation_id'], 'fin_exm_collection_alloc_uq');
            $table->index(['organization_id', 'expected_collection_id'], 'fin_exm_org_collection_idx');
            $table->index(['organization_id', 'payment_allocation_id'], 'fin_exm_org_allocation_idx');
            $table->foreign('organization_id', 'fin_exm_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('matched_by_user_id', 'fecm_matched_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'expected_collection_id'], 'fin_exm_collection_fk')
                ->references(['organization_id', 'id'])->on('finance_expected_collections')->restrictOnDelete();
            $table->foreign(['organization_id', 'payment_allocation_id'], 'fin_exm_allocation_fk')
                ->references(['organization_id', 'id'])->on('payment_allocations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expected_collection_matches');
        Schema::dropIfExists('finance_expected_collections');
        Schema::dropIfExists('financial_account_opening_positions');
    }
};
