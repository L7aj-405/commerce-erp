<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
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
            $table->string('status', 24)->default('pending');
            $table->foreignId('source_entry_id')->nullable()->constrained('commission_entries')->nullOnDelete();
            $table->string('source_key', 191);
            $table->string('product_name_snapshot')->nullable();
            $table->string('line_reference_snapshot')->nullable();
            $table->decimal('quantity_snapshot', 19, 4);
            $table->decimal('revenue_ht_snapshot', 19, 4);
            $table->decimal('purchase_cost_snapshot', 19, 4)->nullable();
            $table->decimal('cost_total_snapshot', 19, 4);
            $table->decimal('margin_amount_snapshot', 19, 4);
            $table->decimal('margin_rate_snapshot', 19, 4);
            $table->decimal('commission_rate_snapshot', 7, 4);
            $table->decimal('commission_amount', 19, 4);
            $table->foreignId('commission_rule_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('commission_rule_set_name_snapshot');
            $table->foreignId('commission_rule_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('rule_min_margin_snapshot', 19, 4)->nullable();
            $table->decimal('rule_max_margin_snapshot', 19, 4)->nullable();
            $table->date('sale_date');
            $table->timestamp('occurred_at');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'source_key'], 'ce_org_source_key_uq');
            $table->index(['organization_id', 'occurred_at'], 'ce_org_occurred_idx');
            $table->index(['organization_id', 'salesperson_id', 'occurred_at'], 'ce_org_salesperson_occurred_idx');
            $table->index(['organization_id', 'status', 'occurred_at'], 'ce_org_status_occurred_idx');
            $table->index(['organization_id', 'entry_type', 'occurred_at'], 'ce_org_type_occurred_idx');
            $table->index('sales_order_line_id', 'ce_order_line_idx');
            $table->index('source_entry_id', 'ce_source_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
    }
};
