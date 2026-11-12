<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — isolated Test finance ledger.
 *
 * Physically separate mirrors of the Production finance transaction tables
 * (payments, allocations, refunds, commission entries, expected collections
 * and their matches). The finance models route to them only while a Test
 * context is active, so Production cash / bank / TPE totals, receivables,
 * revenue and commission reports — including every raw report query — can
 * never see a Test transaction. Financial accounts, stores, users and
 * commission rule sets stay shared master data (referenced, never copied).
 * Internal references are composite (sandbox_id, id) foreign keys: a Test row
 * can never point at a Production row or at another sandbox's row.
 */
return new class extends Migration
{
    private const TABLES = [
        'sandbox_finance_expected_collection_matches', 'sandbox_finance_expected_collections',
        'sandbox_commission_entries', 'sandbox_payment_refunds', 'sandbox_payment_allocations', 'sandbox_payments',
    ];

    public function up(): void
    {
        Schema::create('sandbox_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->string('payment_number', 64);
            $table->string('method', 32);
            $table->string('status', 32)->default('posted');
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->string('external_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_operation_id')->nullable();
            $table->unsignedInteger('operation_sequence')->default(1);
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_pay_sandbox_id_unique');
            $table->unique(['organization_id', 'payment_number'], 'sbx_pay_number_unique');
            $table->unique(['sandbox_id', 'organization_id', 'store_id', 'client_operation_id', 'operation_sequence'], 'sbx_pay_operation_unique');
            $table->index(['sandbox_id', 'organization_id', 'status', 'payment_date'], 'sbx_pay_status_date_idx');
            $table->foreign(['organization_id', 'store_id'], 'sbx_pay_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_account_id'], 'sbx_pay_account_fk')->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
        });

        Schema::create('sandbox_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_pay_alloc_sandbox_id_unique');
            $table->unique(['sandbox_id', 'organization_id', 'payment_id', 'sales_order_id'], 'sbx_pay_alloc_payment_order_unique');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_id'], 'sbx_pay_alloc_order_idx');
            $table->foreign(['sandbox_id', 'payment_id'], 'sbx_pay_alloc_payment_fk')->references(['sandbox_id', 'id'])->on('sandbox_payments')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'sbx_pay_alloc_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });

        Schema::create('sandbox_payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->string('refund_number', 64);
            $table->string('method', 32);
            $table->string('status', 32)->default('posted');
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('refund_date');
            $table->text('reason');
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unsignedBigInteger('customer_return_id')->nullable();
            $table->uuid('client_operation_id')->nullable();
            $table->unique(['sandbox_id', 'id'], 'sbx_refund_sandbox_id_unique');
            $table->unique(['organization_id', 'refund_number'], 'sbx_refund_number_unique');
            $table->unique(['sandbox_id', 'organization_id', 'store_id', 'client_operation_id'], 'sbx_refund_operation_unique');
            $table->index(['sandbox_id', 'organization_id', 'payment_id'], 'sbx_refund_payment_idx');
            $table->foreign(['sandbox_id', 'payment_id'], 'sbx_refund_payment_fk')->references(['sandbox_id', 'id'])->on('sandbox_payments')->restrictOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'sbx_refund_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'sbx_refund_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_account_id'], 'sbx_refund_account_fk')->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
            $table->foreign(['organization_id', 'customer_return_id'], 'sbx_refund_return_fk')->references(['organization_id', 'id'])->on('customer_returns')->restrictOnDelete();
        });

        Schema::create('sandbox_commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('salesperson_name_snapshot')->nullable();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_return_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_return_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_type', 32);
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('source_entry_id')->nullable();
            $table->string('source_key');
            $table->string('product_name_snapshot')->nullable();
            $table->string('line_reference_snapshot')->nullable();
            $table->decimal('quantity_snapshot', 19, 4);
            $table->decimal('revenue_ht_snapshot', 19, 4);
            $table->decimal('purchase_cost_snapshot', 19, 4)->nullable();
            $table->decimal('cost_total_snapshot', 19, 4);
            $table->decimal('margin_amount_snapshot', 19, 4);
            $table->decimal('margin_rate_snapshot', 19, 4);
            $table->decimal('commission_rate_snapshot', 19, 4);
            $table->decimal('commission_amount', 19, 4);
            $table->foreignId('commission_rule_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('commission_rule_set_name_snapshot');
            $table->foreignId('commission_rule_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('rule_min_margin_snapshot', 19, 4)->nullable();
            $table->decimal('rule_max_margin_snapshot', 19, 4)->nullable();
            $table->date('sale_date');
            $table->dateTime('occurred_at');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_ce_sandbox_id_unique');
            $table->unique(['sandbox_id', 'organization_id', 'source_key'], 'sbx_ce_source_key_unique');
            $table->index(['sandbox_id', 'organization_id', 'occurred_at'], 'sbx_ce_occurred_idx');
            $table->foreign(['sandbox_id', 'source_entry_id'], 'sbx_ce_source_entry_fk')->references(['sandbox_id', 'id'])->on('sandbox_commission_entries')->restrictOnDelete();
        });

        Schema::create('sandbox_finance_expected_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('sales_order_id');
            $table->string('label')->nullable();
            $table->string('reference')->nullable();
            $table->date('expected_date');
            $table->decimal('expected_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('state', 32)->default('planned');
            $table->text('notes')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_fin_exp_sandbox_id_unique');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_id'], 'sbx_fin_exp_order_idx');
            $table->foreign(['organization_id', 'sales_order_id'], 'sbx_fin_exp_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'sbx_fin_exp_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
        });

        Schema::create('sandbox_finance_expected_collection_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('expected_collection_id');
            $table->unsignedBigInteger('payment_allocation_id');
            $table->decimal('amount', 19, 4);
            $table->foreignId('matched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sandbox_id', 'organization_id', 'expected_collection_id', 'payment_allocation_id'], 'sbx_fin_exm_collection_alloc_unique');
            $table->foreign(['sandbox_id', 'expected_collection_id'], 'sbx_fin_exm_collection_fk')->references(['sandbox_id', 'id'])->on('sandbox_finance_expected_collections')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'payment_allocation_id'], 'sbx_fin_exm_allocation_fk')->references(['sandbox_id', 'id'])->on('sandbox_payment_allocations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Dropping these tables would silently erase Test payments while their
        // Test orders keep a paid status: refuse while any Test finance exists.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove the Test finance ledger while Test finance records exist.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
