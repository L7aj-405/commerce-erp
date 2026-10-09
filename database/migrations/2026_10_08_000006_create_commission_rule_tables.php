<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 24)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'effective_from'], 'crs_org_status_from_idx');
            $table->unique(['organization_id', 'id'], 'crs_org_id_uq');
        });

        Schema::create('commission_rule_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('commission_rule_set_id');
            $table->decimal('min_margin_rate', 19, 4)->nullable();
            $table->decimal('max_margin_rate', 19, 4)->nullable();
            $table->decimal('commission_rate', 7, 4);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['commission_rule_set_id', 'sort_order'], 'crt_set_order_uq');
            $table->index(['organization_id', 'commission_rule_set_id'], 'crt_org_set_idx');
            $table->foreign(['organization_id', 'commission_rule_set_id'], 'crt_org_set_fk')
                ->references(['organization_id', 'id'])->on('commission_rule_sets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rule_tiers');
        Schema::dropIfExists('commission_rule_sets');
    }
};
