<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual WooCommerce stock update queue (Part A of the manual Woo bridge).
 *
 * The ERP does NOT write stock to WooCommerce automatically. Whenever a
 * Woo-linked ProductVariant loses company stock through a customer sale, one
 * row is recorded here so an operator can reflect the change on the
 * WooCommerce website by hand and tick it off. Completing a task never
 * touches inventory — see RecordWooCommerceStockTaskAction /
 * CompleteWooCommerceStockTaskAction.
 *
 * Idempotency: `source_type` + `source_id` identifies the exact authoritative
 * InventoryMovement row that caused the task, and is unique per organization —
 * a retried/duplicated request for the same movement can never create a
 * second task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_stock_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            // No FK — mirrors product_channel_identifiers.woocommerce_integration_id
            // (a composite FK here would require RESTRICT, which is too strong for a
            // historical/completed operational record). Informational only.
            $table->unsignedBigInteger('woocommerce_integration_id')->nullable();

            // Signed, e.g. "-2.0000" — copied verbatim from the authoritative
            // InventoryMovement.quantity that caused this task.
            $table->decimal('quantity_delta', 19, 4);

            // Authoritative source event. FQCN + id, e.g. App\Models\InventoryMovement.
            $table->string('source_type', 191);
            $table->unsignedBigInteger('source_id');
            // Snapshot of the human-readable source reference (e.g. the Sales Order
            // number) at task-creation time, for display without extra joins.
            $table->string('source_reference')->nullable();
            $table->string('reason', 191);

            $table->string('status', 20)->default('pending');
            // 'manual' today; reserved for a future automatic-write-back completion
            // without needing a schema change (see class doc on the model).
            $table->string('completed_via', 20)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'source_type', 'source_id'], 'woo_stock_task_source_unique');
            $table->index(['organization_id', 'status'], 'woo_stock_task_org_status_idx');
            $table->index(['organization_id', 'created_at'], 'woo_stock_task_org_created_idx');
            $table->index(['organization_id', 'product_variant_id'], 'woo_stock_task_org_variant_idx');

            $table->foreign(['organization_id', 'store_id'], 'woo_stock_task_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_id'], 'woo_stock_task_product_fk')
                ->references(['organization_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'woo_stock_task_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->cascadeOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'woo_stock_task_warehouse_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_stock_tasks');
    }
};
