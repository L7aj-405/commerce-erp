<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — isolated Test inventory layer.
 *
 * Physically separate tables mirror the Production inventory ledger
 * (balances, immutable movements, reservations, sales allocations, stock
 * transfers). The inventory models route to these tables only while a Test
 * context is active; Production tables are never written (nor read) by Test
 * requests. Every row carries its owning sandbox and every internal reference
 * is a composite (sandbox_id, id) foreign key, so a Test row can never point
 * at a Production ledger row or at another sandbox's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sandboxes', function (Blueprint $table) {
            $table->timestamp('inventory_initialized_at')->nullable();
        });

        Schema::create('sandbox_inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('on_hand', 19, 4)->default(0);
            $table->decimal('reserved', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['sandbox_id', 'organization_id', 'warehouse_id', 'product_variant_id'], 'sbx_inv_bal_identity_unique');
            $table->unique(['sandbox_id', 'id'], 'sbx_inv_bal_sandbox_id_unique');
            $table->index(['sandbox_id', 'organization_id', 'product_variant_id'], 'sbx_inv_bal_variant_idx');
            $this->masterDataKeys($table, 'sbx_inv_bal');
        });

        Schema::create('sandbox_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('movement_type', 48);
            $table->decimal('quantity', 19, 4);
            $table->decimal('quantity_before', 19, 4);
            $table->decimal('quantity_after', 19, 4);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['sandbox_id', 'id'], 'sbx_inv_mov_sandbox_id_unique');
            $table->index(['sandbox_id', 'organization_id', 'created_at'], 'sbx_inv_mov_date_idx');
            $table->index(['sandbox_id', 'organization_id', 'product_variant_id'], 'sbx_inv_mov_variant_idx');
            $this->masterDataKeys($table, 'sbx_inv_mov');
        });

        Schema::create('sandbox_inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 19, 4);
            $table->string('status', 32)->default('active');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_inv_res_sandbox_id_unique');
            $table->unique(['sandbox_id', 'organization_id', 'warehouse_id', 'product_variant_id', 'reference_type', 'reference_id'], 'sbx_inv_res_reference_unique');
            $table->index(['sandbox_id', 'organization_id', 'status'], 'sbx_inv_res_status_idx');
            $this->masterDataKeys($table, 'sbx_inv_res');
        });

        Schema::create('sandbox_sales_order_inventory_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity', 19, 4);
            $table->unsignedBigInteger('inventory_reservation_id')->nullable();
            $table->timestamps();
            $table->unique(['sandbox_id', 'id'], 'sbx_alloc_sandbox_id_unique');
            $table->unique(['sandbox_id', 'organization_id', 'sales_order_line_id', 'warehouse_id'], 'sbx_alloc_line_wh_unique');
            $table->unique(['sandbox_id', 'inventory_reservation_id'], 'sbx_alloc_res_unique');
            $table->foreign(['organization_id', 'sales_order_line_id'], 'sbx_alloc_line_fk')
                ->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'sbx_alloc_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            // A Test allocation can only reference a reservation of the SAME sandbox.
            $table->foreign(['sandbox_id', 'inventory_reservation_id'], 'sbx_alloc_res_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_inventory_reservations')->restrictOnDelete();
        });

        Schema::create('sandbox_stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
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
            $table->unique(['sandbox_id', 'id'], 'sbx_stock_transfer_sandbox_id_unique');
            $table->unique(['organization_id', 'transfer_number'], 'sbx_stock_transfer_number_unique');
            $table->index(['sandbox_id', 'organization_id', 'transferred_at'], 'sbx_stock_transfer_date_idx');
        });

        Schema::create('sandbox_stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sandbox_id')->constrained('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 19, 4);
            $table->unsignedBigInteger('source_out_movement_id');
            $table->unsignedBigInteger('destination_in_movement_id');
            $table->timestamps();
            $table->index(['sandbox_id', 'stock_transfer_id'], 'sbx_stock_transfer_line_idx');
            $table->foreign(['sandbox_id', 'stock_transfer_id'], 'sbx_stock_transfer_line_transfer_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_stock_transfers')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'sbx_stock_transfer_line_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'source_out_movement_id'], 'sbx_stock_transfer_line_out_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_inventory_movements')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'destination_in_movement_id'], 'sbx_stock_transfer_line_in_fk')
                ->references(['sandbox_id', 'id'])->on('sandbox_inventory_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // The initialization snapshot is derived data and may be dropped; any
        // Test inventory activity (or a Test order confirmed against Test
        // stock) would be silently lost or left inconsistent, so refuse.
        $activity = DB::table('sandbox_inventory_reservations')->exists()
            || DB::table('sandbox_sales_order_inventory_allocations')->exists()
            || DB::table('sandbox_stock_transfers')->exists()
            || DB::table('sandbox_inventory_movements')->where('reference_type', '!=', 'sandbox_initialization')->exists()
            || DB::table('sales_orders')->whereNotNull('sandbox_id')->where('status', '!=', 'draft')->exists();
        if ($activity) {
            throw new RuntimeException('Cannot remove the Test inventory layer while Test inventory activity exists.');
        }

        foreach (['sandbox_stock_transfer_lines', 'sandbox_stock_transfers', 'sandbox_sales_order_inventory_allocations',
            'sandbox_inventory_reservations', 'sandbox_inventory_movements', 'sandbox_inventory_balances'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('sandboxes', fn (Blueprint $table) => $table->dropColumn('inventory_initialized_at'));
    }

    /** Shared master data (warehouses / variants) referenced within the organization, never duplicated. */
    private function masterDataKeys(Blueprint $table, string $prefix): void
    {
        $table->foreign(['organization_id', 'warehouse_id'], $prefix.'_wh_fk')
            ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
        $table->foreign(['organization_id', 'product_variant_id'], $prefix.'_variant_fk')
            ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
    }
};
