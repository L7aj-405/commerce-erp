<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_month_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->date('period');
            $table->string('status', 16)->default('open');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by_user_id')->nullable();
            $table->text('note')->nullable();
            $table->json('close_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'fin_month_review_org_id_uq');
            $table->unique(['organization_id', 'period'], 'fin_month_review_org_period_uq');
            $table->index(['organization_id', 'status', 'period'], 'fin_month_review_org_status_period_idx');
            $table->foreign('organization_id', 'fin_month_review_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id', 'fmr_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by_user_id', 'fmr_closed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reopened_by_user_id', 'fmr_reopened_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_month_reviews');
    }
};
