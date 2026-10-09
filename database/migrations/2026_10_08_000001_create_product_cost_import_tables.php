<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cost_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_file_name');
            $table->string('source_format', 16);
            $table->string('status', 24)->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('not_found_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'pci_org_id_unique');
            $table->index(['organization_id', 'created_at'], 'pci_org_created_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('product_cost_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('product_cost_import_id');
            $table->unsignedInteger('row_number');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('variant_label')->nullable();
            $table->string('sku')->nullable();
            $table->string('reference')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('current_purchase_price', 19, 4)->nullable();
            $table->decimal('new_purchase_price', 19, 4)->nullable();
            $table->string('status', 24);
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['product_cost_import_id', 'row_number'], 'pcir_import_row_unique');
            $table->index(['organization_id', 'product_cost_import_id', 'status'], 'pcir_org_import_status_idx');
            $table->foreign(['organization_id', 'product_cost_import_id'], 'pcir_import_fk')
                ->references(['organization_id', 'id'])->on('product_cost_imports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cost_import_rows');
        Schema::dropIfExists('product_cost_imports');
    }
};
