<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse-to-warehouse stock transfers — consolidated baseline.
 *
 * Schema is unchanged from create_stock_transfer_tables; only the inline
 * `inventory.transfer` permission provisioning was removed (now handled by the
 * single provision_permissions data migration).
 *
 * Note: source/destination warehouse references are single-column FKs to
 * warehouses.id (RESTRICT) — this is the pre-existing shape and is left as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('transfer_number', 32);
            $table->string('status', 32)->default('completed');
            $table->text('reason')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->decimal('unit_count', 19, 4)->default(0);
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->useCurrent();
            $table->timestamps();
            $table->unique(['organization_id', 'transfer_number'], 'stock_transfer_number_unique');
            $table->unique(['organization_id', 'id'], 'stock_transfer_org_id_unique');
            $table->index(['organization_id', 'transferred_at'], 'stock_transfer_org_date_idx');
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 19, 4);
            $table->unsignedBigInteger('source_out_movement_id');
            $table->unsignedBigInteger('destination_in_movement_id');
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'stock_transfer_line_org_id_unique');
            $table->index(['organization_id', 'stock_transfer_id'], 'stock_transfer_line_transfer_idx');
            $table->foreign(['organization_id', 'product_variant_id'], 'stock_transfer_line_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['organization_id', 'source_out_movement_id'], 'stock_transfer_line_out_fk')
                ->references(['organization_id', 'id'])->on('inventory_movements')->restrictOnDelete();
            $table->foreign(['organization_id', 'destination_in_movement_id'], 'stock_transfer_line_in_fk')
                ->references(['organization_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_transfer_sequences');
    }
};
