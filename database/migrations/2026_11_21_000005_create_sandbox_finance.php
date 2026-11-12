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
 *
 * Internal references are composite (sandbox_id, id) foreign keys: a Test row
 * can never point at a Production row or at another sandbox's row.
 */
return new class extends Migration
{
    private const TABLES = [
        'sandbox_finance_expected_collection_matches',
        'sandbox_finance_expected_collections',
        'sandbox_commission_entries',
        'sandbox_payment_refunds',
        'sandbox_payment_allocations',
        'sandbox_payments',
    ];

    public function up(): void
    {
        Schema::create('sandbox_payments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sandbox_id');
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

            $table->unsignedBigInteger('received_by_user_id')->nullable();

            $table->uuid('client_operation_id')->nullable();
            $table->unsignedInteger('operation_sequence')->default(1);

            $table->dateTime('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['sandbox_id', 'id'],
                'sbx_pay_sandbox_id_unique'
            );

            $table->unique(
                ['organization_id', 'payment_number'],
                'sbx_pay_number_unique'
            );

            $table->unique(
                [
                    'sandbox_id',
                    'organization_id',
                    'store_id',
                    'client_operation_id',
                    'operation_sequence',
                ],
                'sbx_pay_operation_unique'
            );

            $table->index(
                ['sandbox_id', 'organization_id', 'status', 'payment_date'],
                'sbx_pay_status_date_idx'
            );

            $table->foreign('sandbox_id', 'sbx_pay_sandbox_fk')
                ->references('id')
                ->on('sandboxes')
                ->restrictOnDelete();

            $table->foreign('received_by_user_id', 'sbx_pay_received_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('reversed_by_user_id', 'sbx_pay_reversed_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign(
                ['organization_id', 'store_id'],
                'sbx_pay_store_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('stores')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'financial_account_id'],
                'sbx_pay_account_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('financial_accounts')
                ->restrictOnDelete();
        });

        Schema::create('sandbox_payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sandbox_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('sales_order_id');

            $table->decimal('amount', 19, 4);

            $table->timestamps();

            $table->unique(
                ['sandbox_id', 'id'],
                'sbx_pay_alloc_sandbox_id_unique'
            );

            $table->unique(
                ['sandbox_id', 'organization_id', 'payment_id', 'sales_order_id'],
                'sbx_pay_alloc_payment_order_unique'
            );

            $table->index(
                ['sandbox_id', 'organization_id', 'sales_order_id'],
                'sbx_pay_alloc_order_idx'
            );

            $table->foreign('sandbox_id', 'sbx_pay_alloc_sandbox_fk')
                ->references('id')
                ->on('sandboxes')
                ->restrictOnDelete();

            $table->foreign(
                ['sandbox_id', 'payment_id'],
                'sbx_pay_alloc_payment_fk'
            )
                ->references(['sandbox_id', 'id'])
                ->on('sandbox_payments')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'sales_order_id'],
                'sbx_pay_alloc_order_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('sales_orders')
                ->restrictOnDelete();
        });

        Schema::create('sandbox_payment_refunds', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sandbox_id');
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

            $table->unsignedBigInteger('refunded_by_user_id')->nullable();

            $table->timestamps();

            $table->unsignedBigInteger('customer_return_id')->nullable();
            $table->uuid('client_operation_id')->nullable();

            $table->unique(
                ['sandbox_id', 'id'],
                'sbx_refund_sandbox_id_unique'
            );

            $table->unique(
                ['organization_id', 'refund_number'],
                'sbx_refund_number_unique'
            );

            $table->unique(
                [
                    'sandbox_id',
                    'organization_id',
                    'store_id',
                    'client_operation_id',
                ],
                'sbx_refund_operation_unique'
            );

            $table->index(
                ['sandbox_id', 'organization_id', 'payment_id'],
                'sbx_refund_payment_idx'
            );

            $table->foreign('sandbox_id', 'sbx_ref_sandbox_fk')
                ->references('id')
                ->on('sandboxes')
                ->restrictOnDelete();

            $table->foreign('refunded_by_user_id', 'sbx_ref_refunded_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign(
                ['sandbox_id', 'payment_id'],
                'sbx_refund_payment_fk'
            )
                ->references(['sandbox_id', 'id'])
                ->on('sandbox_payments')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'store_id'],
                'sbx_refund_store_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('stores')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'sales_order_id'],
                'sbx_refund_order_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('sales_orders')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'financial_account_id'],
                'sbx_refund_account_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('financial_accounts')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'customer_return_id'],
                'sbx_refund_return_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('customer_returns')
                ->restrictOnDelete();
        });

        Schema::create('sandbox_commission_entries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sandbox_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('salesperson_id')->nullable();

            $table->string('salesperson_name_snapshot')->nullable();

            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('sales_order_revision_id')->nullable();

            $table->unsignedBigInteger('customer_return_id')->nullable();
            $table->unsignedBigInteger('customer_return_line_id')->nullable();

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

            $table->unsignedBigInteger('commission_rule_set_id')->nullable();
            $table->string('commission_rule_set_name_snapshot');

            $table->unsignedBigInteger('commission_rule_tier_id')->nullable();

            $table->decimal('rule_min_margin_snapshot', 19, 4)->nullable();
            $table->decimal('rule_max_margin_snapshot', 19, 4)->nullable();

            $table->date('sale_date');
            $table->dateTime('occurred_at');

            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->dateTime('paid_at')->nullable();

            $table->string('reversal_reason')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(
                ['sandbox_id', 'id'],
                'sbx_ce_sandbox_id_unique'
            );

            $table->unique(
                ['sandbox_id', 'organization_id', 'source_key'],
                'sbx_ce_source_key_unique'
            );

            $table->index(
                ['sandbox_id', 'organization_id', 'occurred_at'],
                'sbx_ce_occurred_idx'
            );

            $table->foreign('sandbox_id', 'sbx_ce_sandbox_fk')
                ->references('id')
                ->on('sandboxes')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'sbx_ce_org_fk')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('store_id', 'sbx_ce_store_fk')
                ->references('id')
                ->on('stores')
                ->nullOnDelete();

            $table->foreign('salesperson_id', 'sbx_ce_salesperson_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('sales_order_id', 'sbx_ce_order_fk')
                ->references('id')
                ->on('sales_orders')
                ->restrictOnDelete();

            $table->foreign('sales_order_line_id', 'sbx_ce_order_line_fk')
                ->references('id')
                ->on('sales_order_lines')
                ->nullOnDelete();

            $table->foreign('sales_order_revision_id', 'sbx_ce_order_rev_fk')
                ->references('id')
                ->on('sales_order_revisions')
                ->nullOnDelete();

            $table->foreign('customer_return_id', 'sbx_ce_return_fk')
                ->references('id')
                ->on('customer_returns')
                ->nullOnDelete();

            $table->foreign('customer_return_line_id', 'sbx_ce_return_line_fk')
                ->references('id')
                ->on('customer_return_lines')
                ->nullOnDelete();

            $table->foreign('commission_rule_set_id', 'sbx_ce_rule_set_fk')
                ->references('id')
                ->on('commission_rule_sets')
                ->nullOnDelete();

            $table->foreign('commission_rule_tier_id', 'sbx_ce_rule_tier_fk')
                ->references('id')
                ->on('commission_rule_tiers')
                ->nullOnDelete();

            $table->foreign('approved_by_user_id', 'sbx_ce_approved_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('paid_by_user_id', 'sbx_ce_paid_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign(
                ['sandbox_id', 'source_entry_id'],
                'sbx_ce_source_entry_fk'
            )
                ->references(['sandbox_id', 'id'])
                ->on('sandbox_commission_entries')
                ->restrictOnDelete();
        });

        Schema::create('sandbox_finance_expected_collections', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sandbox_id');
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
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamps();

            $table->unique(
                ['sandbox_id', 'id'],
                'sbx_fin_exp_sandbox_id_unique'
            );

            $table->index(
                ['sandbox_id', 'organization_id', 'sales_order_id'],
                'sbx_fin_exp_order_idx'
            );

            $table->foreign('sandbox_id', 'sbx_fec_sandbox_fk')
                ->references('id')
                ->on('sandboxes')
                ->restrictOnDelete();

            $table->foreign('cancelled_by_user_id', 'sbx_fec_cancel_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('created_by_user_id', 'sbx_fec_created_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign(
                ['organization_id', 'sales_order_id'],
                'sbx_fin_exp_order_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('sales_orders')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'store_id'],
                'sbx_fin_exp_store_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('stores')
                ->restrictOnDelete();
        });

        Schema::create('sandbox_finance_expected_collection_matches', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sandbox_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('expected_collection_id');
            $table->unsignedBigInteger('payment_allocation_id');

            $table->decimal('amount', 19, 4);

            $table->unsignedBigInteger('matched_by_user_id')->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'sandbox_id',
                    'organization_id',
                    'expected_collection_id',
                    'payment_allocation_id',
                ],
                'sbx_fin_exm_collection_alloc_unique'
            );

            $table->foreign('sandbox_id', 'sbx_fem_sandbox_fk')
                ->references('id')
                ->on('sandboxes')
                ->restrictOnDelete();

            $table->foreign('matched_by_user_id', 'sbx_fem_matched_usr_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign(
                ['sandbox_id', 'expected_collection_id'],
                'sbx_fin_exm_collection_fk'
            )
                ->references(['sandbox_id', 'id'])
                ->on('sandbox_finance_expected_collections')
                ->restrictOnDelete();

            $table->foreign(
                ['sandbox_id', 'payment_allocation_id'],
                'sbx_fin_exm_allocation_fk'
            )
                ->references(['sandbox_id', 'id'])
                ->on('sandbox_payment_allocations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Dropping these tables would silently erase Test payments while their
        // Test orders keep a paid status: refuse while any Test finance exists.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Cannot remove the Test finance ledger while Test finance records exist.'
                );
            }
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};