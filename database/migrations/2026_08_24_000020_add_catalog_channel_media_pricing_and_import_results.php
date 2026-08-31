<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->after('description');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('regular_sale_price', 19, 4)->default(0)->after('default_sale_price');
            $table->decimal('promotional_sale_price', 19, 4)->nullable()->after('regular_sale_price');
        });
        DB::table('product_variants')->update(['regular_sale_price' => DB::raw('default_sale_price')]);

        Schema::create('product_channel_identifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('source', 32);
            $table->string('external_product_id');
            $table->string('external_stock_status', 32)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'source', 'external_product_id'], 'product_channel_external_unique');
            $table->index(['organization_id', 'product_id', 'source'], 'product_channel_product_idx');
            $table->index(['organization_id', 'product_variant_id', 'source'], 'product_channel_variant_idx');
            $table->foreign(['organization_id', 'product_id'], 'product_channel_product_fk')
                ->references(['organization_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'product_channel_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::table('product_imports', function (Blueprint $table) {
            $table->unsignedInteger('created_product_count')->default(0)->after('imported_count');
            $table->unsignedInteger('created_variant_count')->default(0)->after('created_product_count');
            $table->unsignedInteger('linked_image_url_count')->default(0)->after('created_variant_count');
            $table->unsignedInteger('invalid_or_missing_image_url_count')->default(0)->after('linked_image_url_count');
            $table->unsignedInteger('initialized_stock_count')->default(0)->after('invalid_or_missing_image_url_count');
        });
    }

    public function down(): void
    {
        Schema::table('product_imports', function (Blueprint $table) {
            $table->dropColumn([
                'created_product_count', 'created_variant_count', 'linked_image_url_count',
                'invalid_or_missing_image_url_count', 'initialized_stock_count',
            ]);
        });
        Schema::dropIfExists('product_channel_identifiers');
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['regular_sale_price', 'promotional_sale_price']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
