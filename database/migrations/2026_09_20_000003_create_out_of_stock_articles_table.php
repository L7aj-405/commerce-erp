<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Articles hors stock" (Part B): a customer/order needs an article that is
 * not yet a normal catalogue/stock Product/ProductVariant. One row per
 * Custom Sales Order line an agent explicitly flags for the catalogue team to
 * source or create. Resolving a row only LINKS it to the real ProductVariant
 * once one exists — it never fabricates inventory (§14) and never rewrites the
 * historical (already-sold) Sales Order line.
 *
 * Deliberately NOT modeled on `sales_order_procurements`: that table requires
 * an existing product_variant_id (a supplier special-order replenishes a
 * catalogue item that is merely out of physical stock). A genuinely new,
 * not-yet-catalogued article has no ProductVariant to attach a procurement to,
 * so this is its own small explicit model — see the class doc on
 * OutOfStockArticle for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('out_of_stock_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('customer_id')->nullable();

            // Snapshot of the Custom line at flagging time — the request stays
            // legible even if the line itself is later edited (Draft) or the
            // Order is long gone from daily view.
            $table->string('description');
            $table->decimal('requested_quantity', 19, 4);
            $table->string('reference')->nullable();

            $table->string('status', 20)->default('unresolved');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('resolved_product_variant_id')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // One active request per Custom line — re-flagging is a no-op that
            // returns the existing row (idempotent, mirrors §19 for Part A).
            $table->unique(['organization_id', 'sales_order_line_id'], 'oosa_org_line_unique');
            $table->index(['organization_id', 'status'], 'oosa_org_status_idx');
            $table->index(['organization_id', 'created_at'], 'oosa_org_created_idx');

            $table->foreign(['organization_id', 'sales_order_id'], 'oosa_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_line_id'], 'oosa_line_fk')
                ->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'customer_id'], 'oosa_customer_fk')
                ->references(['organization_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['organization_id', 'resolved_product_variant_id'], 'oosa_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('out_of_stock_articles');
    }
};
