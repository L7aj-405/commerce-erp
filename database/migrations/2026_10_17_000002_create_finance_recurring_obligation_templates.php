<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_recurring_template_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->primary(['organization_id', 'year'], 'fin_rec_seq_pk');
            $table->foreign('organization_id', 'fin_rec_seq_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('finance_recurring_obligation_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('template_number', 32);
            $table->string('category', 32);
            $table->string('label');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('beneficiary_name')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('planned_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->string('planned_payment_method', 32)->nullable();
            $table->unsignedBigInteger('preferred_financial_account_id')->nullable();
            $table->string('due_day_mode', 24);
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_rec_org_id_uq');
            $table->unique(['organization_id', 'template_number'], 'fin_rec_org_number_uq');
            $table->index(['organization_id', 'active'], 'fin_rec_org_active_idx');
            $table->index(['organization_id', 'store_id', 'active'], 'fin_rec_org_store_active_idx');
            $table->index(['organization_id', 'supplier_id', 'active'], 'fin_rec_org_supplier_active_idx');
            $table->index(['organization_id', 'start_date'], 'fin_rec_org_start_idx');
            $table->index(['organization_id', 'end_date'], 'fin_rec_org_end_idx');

            $table->foreign('organization_id', 'fin_rec_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'frt_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deactivated_by_user_id', 'frt_deactivated_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'fin_rec_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'fin_rec_supplier_fk')->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'preferred_financial_account_id'], 'fin_rec_account_fk')->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
        });

        Schema::table('finance_obligations', function (Blueprint $table) {
            $table->unsignedBigInteger('recurring_template_id')->nullable()->after('organization_id');
            $table->date('recurring_period')->nullable()->after('recurring_template_id');
            $table->unique(['organization_id', 'recurring_template_id', 'recurring_period'], 'fin_obl_rec_period_uq');
            $table->foreign(['organization_id', 'recurring_template_id'], 'fin_obl_rec_template_fk')
                ->references(['organization_id', 'id'])->on('finance_recurring_obligation_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_obligations', function (Blueprint $table) {
            $table->dropForeign('fin_obl_rec_template_fk');
            $table->dropUnique('fin_obl_rec_period_uq');
            $table->dropColumn(['recurring_template_id', 'recurring_period']);
        });
        Schema::dropIfExists('finance_recurring_obligation_templates');
        Schema::dropIfExists('finance_recurring_template_sequences');
    }
};
