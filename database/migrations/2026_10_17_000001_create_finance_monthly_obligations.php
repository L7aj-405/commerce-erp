<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_obligation_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->primary(['organization_id', 'year'], 'fin_obl_seq_pk');
            $table->foreign('organization_id', 'fin_obl_seq_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('finance_outgoing_payment_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->primary(['organization_id', 'year'], 'fin_out_seq_pk');
            $table->foreign('organization_id', 'fin_out_seq_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('finance_obligations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('obligation_number', 32);
            $table->string('category', 32);
            $table->string('label');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('beneficiary_name')->nullable();
            $table->string('reference')->nullable();
            $table->date('due_date');
            $table->decimal('planned_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('planned_payment_method', 32)->nullable();
            $table->unsignedBigInteger('preferred_financial_account_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_obl_org_id_uq');
            $table->unique(['organization_id', 'obligation_number'], 'fin_obl_org_number_uq');
            $table->index(['organization_id', 'due_date'], 'fin_obl_org_due_idx');
            $table->index(['organization_id', 'store_id', 'due_date'], 'fin_obl_org_store_due_idx');
            $table->index(['organization_id', 'category', 'due_date'], 'fin_obl_org_cat_due_idx');
            $table->index(['organization_id', 'supplier_id', 'due_date'], 'fin_obl_org_supplier_due_idx');

            $table->foreign('organization_id', 'fin_obl_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'fin_obl_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by_user_id', 'fin_obl_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'fin_obl_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'fin_obl_supplier_fk')->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'preferred_financial_account_id'], 'fin_obl_account_fk')->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
        });

        Schema::create('finance_outgoing_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('payment_number', 32);
            $table->unsignedBigInteger('financial_account_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('beneficiary_name')->nullable();
            $table->string('payment_method', 32);
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('posted');
            $table->uuid('idempotency_key');
            $table->foreignId('recorded_by_user_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_out_org_id_uq');
            $table->unique(['organization_id', 'payment_number'], 'fin_out_org_number_uq');
            $table->unique(['organization_id', 'idempotency_key'], 'fin_out_org_idem_uq');
            $table->index(['organization_id', 'payment_date'], 'fin_out_org_date_idx');
            $table->index(['organization_id', 'financial_account_id', 'payment_date'], 'fin_out_org_account_date_idx');

            $table->foreign('organization_id', 'fin_out_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('recorded_by_user_id', 'fin_out_recorded_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by_user_id', 'fin_out_reversed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'fin_out_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_account_id'], 'fin_out_account_fk')->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'fin_out_supplier_fk')->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
        });

        Schema::create('finance_outgoing_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('outgoing_payment_id');
            $table->unsignedBigInteger('obligation_id');
            $table->decimal('amount', 19, 4);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_out_alloc_org_id_uq');
            $table->unique(['organization_id', 'outgoing_payment_id', 'obligation_id'], 'fin_out_alloc_payment_obl_uq');
            $table->index(['organization_id', 'obligation_id'], 'fin_out_alloc_obl_idx');
            $table->index(['organization_id', 'outgoing_payment_id'], 'fin_out_alloc_payment_idx');
            $table->foreign('organization_id', 'fin_out_alloc_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'outgoing_payment_id'], 'fin_out_alloc_payment_fk')->references(['organization_id', 'id'])->on('finance_outgoing_payments')->restrictOnDelete();
            $table->foreign(['organization_id', 'obligation_id'], 'fin_out_alloc_obl_fk')->references(['organization_id', 'id'])->on('finance_obligations')->restrictOnDelete();
        });

        app(PermissionProvisioner::class)->provisionAllOrganizations();
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_outgoing_payment_allocations');
        Schema::dropIfExists('finance_outgoing_payments');
        Schema::dropIfExists('finance_obligations');
        Schema::dropIfExists('finance_outgoing_payment_sequences');
        Schema::dropIfExists('finance_obligation_sequences');
    }
};
