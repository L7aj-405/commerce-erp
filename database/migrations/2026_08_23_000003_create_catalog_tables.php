<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->string('sku');
            $table->string('reference')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('purchase_price', 15, 4)->nullable();
            $table->decimal('default_sale_price', 15, 4);
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
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }
};
