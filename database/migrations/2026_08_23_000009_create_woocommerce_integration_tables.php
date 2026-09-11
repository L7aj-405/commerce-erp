<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce integration domain — consolidated baseline.
 *
 * Changes from the historical create_woocommerce_integration_tables:
 *  - default_warehouse_id / default_store_id composite tenant FKs now use
 *    RESTRICT on delete instead of SET NULL. A composite (organization_id, *_id)
 *    SET NULL is illegal because organization_id is NOT NULL — MySQL would try to
 *    null the whole key. The optional column stays nullable and the application
 *    clears it before a referenced warehouse/store is removed.
 *  - the redundant standalone index on (organization_id) is dropped; the
 *    (organization_id, id) unique already indexes that prefix.
 *  - the product_channel_identifiers column additions are folded into the
 *    catalog baseline, where that table is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('store_url', 2048);
            $table->string('consumer_key');
            $table->text('consumer_secret'); // encrypted at the model layer
            $table->unsignedBigInteger('default_warehouse_id')->nullable();
            $table->unsignedBigInteger('default_store_id')->nullable();
            $table->boolean('sync_stock')->default(false);
            // Meaning of Woo prices; null = not yet resolved (detected on first sync).
            $table->boolean('prices_include_tax')->nullable();
            // Where a Brand name can be read from a Woo product, if at all.
            $table->string('brand_source', 24)->nullable();       // taxonomy | attribute | meta
            $table->string('brand_taxonomy', 64)->nullable();
            $table->string('brand_attribute_name', 128)->nullable();
            $table->string('brand_meta_key', 128)->nullable();
            $table->string('reference_meta_key', 128)->nullable();
            $table->dateTime('last_connection_check_at')->nullable();
            $table->boolean('last_connection_ok')->nullable();
            $table->dateTime('last_product_sync_started_at')->nullable();
            $table->dateTime('last_product_sync_completed_at')->nullable();
            // ISO-8601 site time of the newest product processed by a completed run.
            $table->string('last_product_modified_cursor')->nullable();
            $table->unsignedInteger('synced_product_count')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'woo_int_org_id_unique');
            $table->foreign(['organization_id', 'default_warehouse_id'], 'woo_int_warehouse_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['organization_id', 'default_store_id'], 'woo_int_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
        });

        Schema::create('woocommerce_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('woocommerce_integration_id');
            $table->string('type', 24)->default('products');
            $table->string('mode', 24)->default('full');        // full | incremental
            $table->string('status', 32)->default('running');    // running | completed | completed_with_errors | failed
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('products_read')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_skipped')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->unsignedInteger('variants_synced')->default(0);
            $table->unsignedInteger('categories_synced')->default(0);
            $table->unsignedInteger('stock_adjustments')->default(0);
            $table->text('message')->nullable();
            $table->json('errors')->nullable();                  // [{ remote_id, message }]
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'woocommerce_integration_id', 'id'], 'woo_run_int_idx');
            $table->foreign(['organization_id', 'woocommerce_integration_id'], 'woo_run_int_fk')
                ->references(['organization_id', 'id'])->on('woocommerce_integrations')->cascadeOnDelete();
        });

        Schema::create('woocommerce_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('woocommerce_integration_id');
            $table->unsignedBigInteger('remote_category_id');
            $table->unsignedBigInteger('category_id');
            $table->string('remote_name')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'woocommerce_integration_id', 'remote_category_id'], 'woo_cat_map_unique');
            $table->foreign(['organization_id', 'woocommerce_integration_id'], 'woo_cat_map_int_fk')
                ->references(['organization_id', 'id'])->on('woocommerce_integrations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'category_id'], 'woo_cat_map_category_fk')
                ->references(['organization_id', 'id'])->on('categories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_category_mappings');
        Schema::dropIfExists('woocommerce_sync_runs');
        Schema::dropIfExists('woocommerce_integrations');
    }
};
