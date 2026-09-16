<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `product_variants` had no image column at all — only `products.image_url`
 * exists (see 2026_08_23_000002_create_catalog_tables.php). That is the root
 * cause of the WooCommerce variation-image bug: there was nowhere to persist a
 * per-variant image, so every variant displayed the parent Product's shared
 * image_url. Additive, nullable — no backfill needed; existing rows simply
 * have no variant-specific image until the next WooCommerce sync populates one
 * (falling back to the parent image, per the documented priority).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->after('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
