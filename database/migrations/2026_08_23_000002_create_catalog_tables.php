<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog domain — consolidated baseline.
 *
 * Folds: create_catalog_tables, standardize_catalog_money_precision (19,4),
 * make_product_variant_sku_nullable, the catalog portions of
 * add_catalog_channel_media_pricing_and_import_results (products.image_url,
 * product_variants.regular/promotional price, product_channel_identifiers),
 * add_public_ttc_price_and_default_tax_rate (tax_rates.is_default),
 * add_store_default_tax_and_variant_public_ttc_price (variant HT/TTC columns +
 * the stores.default_tax_rate_id FK), and the product_channel_identifiers
 * columns added by create_woocommerce_integration_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
            $table->foreign(['organization_id', 'parent_id'])
                ->references(['organization_id', 'id'])
                ->on('categories')
                ->restrictOnDelete();
        });

        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('symbol', 32);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'symbol']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('rate', 7, 4);
            // At most one default per organisation; pre-selects the tax on new variants.
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('default_category_id')->nullable();
            $table->unsignedBigInteger('default_unit_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'brand_id']);
            $table->index(['organization_id', 'default_category_id']);
            $table->foreign(['organization_id', 'brand_id'])->references(['organization_id', 'id'])->on('brands')->restrictOnDelete();
            $table->foreign(['organization_id', 'default_category_id'])->references(['organization_id', 'id'])->on('categories')->restrictOnDelete();
            $table->foreign(['organization_id', 'default_unit_id'])->references(['organization_id', 'id'])->on('units_of_measure')->restrictOnDelete();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('product_id');
            $table->string('label')->nullable();
            // SKU is optional: blank/whitespace WooCommerce or import values become NULL.
            $table->string('sku', 255)->nullable();
            $table->string('reference')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('purchase_price', 19, 4)->nullable();
            $table->decimal('default_sale_price', 19, 4);
            $table->decimal('regular_sale_price', 19, 4)->default(0);
            $table->decimal('promotional_sale_price', 19, 4)->nullable();
            // Explicit customer-facing TTC price and an OPTIONAL explicit HT. When both
            // are NULL the price resolver treats default_sale_price as public and
            // derives HT from the resolved tax rate.
            $table->decimal('public_price_ttc', 19, 4)->nullable();
            $table->decimal('unit_price_ht', 19, 4)->nullable();
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'barcode']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'reference']);
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['organization_id', 'tax_rate_id'])->references(['organization_id', 'id'])->on('tax_rates')->restrictOnDelete();
        });

        // External sales-channel identity map (WooCommerce, CSV import, ...).
        Schema::create('product_channel_identifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            // Integration scoping for WooCommerce rows; NULL for legacy/CSV rows.
            // No FK by design so an import mapping can exist before an integration.
            $table->unsignedBigInteger('woocommerce_integration_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('source', 32);
            $table->string('external_product_id');
            $table->string('remote_parent_id')->nullable();
            $table->string('remote_variation_id')->nullable();
            $table->string('external_stock_status', 32)->nullable();
            $table->dateTime('remote_modified_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'source', 'external_product_id'], 'product_channel_external_unique');
            $table->index(['organization_id', 'product_id', 'source'], 'product_channel_product_idx');
            $table->index(['organization_id', 'product_variant_id', 'source'], 'product_channel_variant_idx');
            $table->index(['organization_id', 'woocommerce_integration_id', 'source'], 'product_channel_int_idx');
            $table->foreign(['organization_id', 'product_id'], 'product_channel_product_fk')
                ->references(['organization_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'product_channel_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        // Cross-domain stitch: stores (platform core) gains its default-tax FK now
        // that tax_rates exists. Composite tenant FK, RESTRICT on delete (the
        // optional column stays nullable; the app clears it before removing a rate).
        Schema::table('stores', function (Blueprint $table) {
            $table->foreign(['organization_id', 'default_tax_rate_id'], 'stores_default_tax_rate_fk')
                ->references(['organization_id', 'id'])->on('tax_rates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign('stores_default_tax_rate_fk');
        });

        Schema::dropIfExists('product_channel_identifiers');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }
};
