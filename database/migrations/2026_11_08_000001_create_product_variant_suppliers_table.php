<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B1 — supplier catalog: which suppliers supply which product variant,
 * on which known terms. Many-to-many, organization-wide (suppliers and the
 * catalog are not store-scoped).
 *
 * - One canonical row per (organization, supplier, variant), active or
 *   inactive: re-linking reactivates the row instead of duplicating it, and
 *   relations are deactivated rather than deleted (audit history).
 * - At most one preferred supplier per variant, enforced by the database:
 *   `preferred_variant_id` mirrors `product_variant_id` only while the row is
 *   preferred (NULL otherwise) under a unique key; the action also locks the
 *   variant row so concurrent preferred changes serialize.
 * - `purchase_price` is the currently known supplier-specific price. It never
 *   overwrites product_variants.purchase_price. Last purchase date/price/PO
 *   are derived from purchase_order_lines, not stored here.
 *
 * Distinct from `product_suppliers` (product-level "remembered" suppliers used
 * by quotation sourcing), which is left unchanged.
 *
 * Prefix `pvs_` for named constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_variant_id');

            $table->string('supplier_sku')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->string('supplier_product_name')->nullable();

            $table->decimal('purchase_price', 19, 4)->nullable();
            $table->char('currency_code', 3);
            $table->decimal('minimum_order_quantity', 19, 4)->nullable();
            $table->decimal('order_multiple', 19, 4)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();

            $table->boolean('is_preferred')->default(false);
            $table->unsignedBigInteger('preferred_variant_id')->nullable();
            $table->unsignedSmallInteger('priority')->nullable();
            // active | inactive
            $table->string('status', 16)->default('active');

            $table->unsignedTinyInteger('quality_rating')->nullable();
            $table->unsignedTinyInteger('reliability_rating')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'pvs_org_id_uq');
            $table->unique(['organization_id', 'supplier_id', 'product_variant_id'], 'pvs_org_supplier_variant_uq');
            $table->unique(['organization_id', 'preferred_variant_id'], 'pvs_org_preferred_uq');
            // Variant detail: suppliers of the variants of one product.
            $table->index(['organization_id', 'product_variant_id', 'status'], 'pvs_org_variant_status_idx');
            // Supplier detail: variants supplied by one supplier.
            $table->index(['organization_id', 'supplier_id', 'status'], 'pvs_org_supplier_status_idx');

            $table->foreign('organization_id', 'pvs_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'pvs_supplier_fk')
                ->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'pvs_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'pvs_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'pvs_updated_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_suppliers');
    }
};
