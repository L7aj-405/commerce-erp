<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['finance_supplier_plan_sequences', 'finance_instrument_sequences'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $prefix = $tableName === 'finance_supplier_plan_sequences' ? 'fin_spl_seq' : 'fin_ins_seq';
                $table->unsignedBigInteger('organization_id'); $table->unsignedSmallInteger('year');
                $table->unsignedBigInteger('next_number')->default(1); $table->timestamps();
                $table->primary(['organization_id', 'year'], $prefix.'_pk');
                $table->foreign('organization_id', $prefix.'_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        Schema::create('finance_supplier_installment_plans', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('organization_id'); $table->string('plan_number', 32);
            $table->unsignedBigInteger('supplier_id'); $table->unsignedBigInteger('sales_order_procurement_id')->nullable();
            $table->string('reference')->nullable(); $table->decimal('agreed_total', 19, 4); $table->char('currency_code', 3);
            $table->text('notes')->nullable(); $table->foreignId('created_by_user_id')->nullable(); $table->timestamps();
            $table->unique(['organization_id', 'id'], 'fin_spl_org_id_uq');
            $table->unique(['organization_id', 'plan_number'], 'fin_spl_org_number_uq');
            $table->index(['organization_id', 'supplier_id'], 'fin_spl_org_supplier_idx');
            $table->index(['organization_id', 'sales_order_procurement_id'], 'fin_spl_org_proc_idx');
            $table->index(['organization_id', 'created_at'], 'fin_spl_org_created_idx');
            $table->foreign('organization_id', 'fin_spl_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'fsip_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'fin_spl_supplier_fk')->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_procurement_id'], 'fin_spl_proc_fk')->references(['organization_id', 'id'])->on('sales_order_procurements')->restrictOnDelete();
        });

        Schema::table('finance_obligations', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_installment_plan_id')->nullable()->after('recurring_period');
            $table->unsignedBigInteger('sales_order_procurement_id')->nullable()->after('supplier_installment_plan_id');
            $table->index(['organization_id', 'supplier_installment_plan_id'], 'fin_obl_plan_idx');
            $table->index(['organization_id', 'sales_order_procurement_id'], 'fin_obl_proc_idx');
            $table->foreign(['organization_id', 'supplier_installment_plan_id'], 'fin_obl_plan_fk')->references(['organization_id', 'id'])->on('finance_supplier_installment_plans')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_procurement_id'], 'fin_obl_proc_fk')->references(['organization_id', 'id'])->on('sales_order_procurements')->restrictOnDelete();
        });

        Schema::create('finance_payment_instruments', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('organization_id'); $table->unsignedBigInteger('store_id')->nullable();
            $table->string('instrument_number', 32); $table->string('type', 16); $table->string('external_reference')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable(); $table->string('beneficiary_name')->nullable();
            $table->unsignedBigInteger('obligation_id'); $table->unsignedBigInteger('financial_account_id');
            $table->decimal('amount', 19, 4); $table->char('currency_code', 3); $table->date('issue_date'); $table->date('maturity_date');
            $table->string('state', 16)->default('issued'); $table->timestamp('presented_at')->nullable(); $table->timestamp('cleared_at')->nullable();
            $table->timestamp('returned_at')->nullable(); $table->unsignedBigInteger('outgoing_payment_id')->nullable();
            $table->uuid('clearing_idempotency_key'); $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable(); $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable(); $table->foreignId('created_by_user_id')->nullable(); $table->timestamps();
            $table->unique(['organization_id', 'id'], 'fin_ins_org_id_uq');
            $table->unique(['organization_id', 'instrument_number'], 'fin_ins_org_number_uq');
            $table->unique(['organization_id', 'clearing_idempotency_key'], 'fin_ins_org_idem_uq');
            $table->unique(['organization_id', 'outgoing_payment_id'], 'fin_ins_org_payment_uq');
            $table->index(['organization_id', 'maturity_date'], 'fin_ins_org_maturity_idx');
            $table->index(['organization_id', 'supplier_id', 'maturity_date'], 'fin_ins_org_supplier_mat_idx');
            $table->index(['organization_id', 'obligation_id'], 'fin_ins_org_obligation_idx');
            $table->index(['organization_id', 'financial_account_id', 'maturity_date'], 'fin_ins_org_account_mat_idx');
            $table->index(['organization_id', 'state', 'maturity_date'], 'fin_ins_org_state_mat_idx');
            $table->foreign('organization_id', 'fin_ins_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('cancelled_by_user_id', 'fpi_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id', 'fpi_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'fin_ins_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'fin_ins_supplier_fk')->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'obligation_id'], 'fin_ins_obligation_fk')->references(['organization_id', 'id'])->on('finance_obligations')->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_account_id'], 'fin_ins_account_fk')->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
            $table->foreign(['organization_id', 'outgoing_payment_id'], 'fin_ins_payment_fk')->references(['organization_id', 'id'])->on('finance_outgoing_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_instruments');
        Schema::table('finance_obligations', function (Blueprint $table) {
            $table->dropForeign('fin_obl_plan_fk'); $table->dropForeign('fin_obl_proc_fk');
            $table->dropIndex('fin_obl_plan_idx'); $table->dropIndex('fin_obl_proc_idx');
            $table->dropColumn(['supplier_installment_plan_id', 'sales_order_procurement_id']);
        });
        Schema::dropIfExists('finance_supplier_installment_plans');
        Schema::dropIfExists('finance_instrument_sequences'); Schema::dropIfExists('finance_supplier_plan_sequences');
    }
};
