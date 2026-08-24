<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'wh_org_code_unique');
            $table->unique(['organization_id', 'id'], 'wh_org_id_unique');
            $table->index(['organization_id', 'status'], 'wh_org_status_idx');
        });

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('on_hand', 19, 4)->default(0);
            $table->decimal('reserved', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'warehouse_id', 'product_variant_id'], 'inv_bal_identity_unique');
            $table->unique(['organization_id', 'id'], 'inv_bal_org_id_unique');
            $table->index(['organization_id', 'warehouse_id'], 'inv_bal_org_wh_idx');
            $table->index(['organization_id', 'product_variant_id'], 'inv_bal_org_variant_idx');
            $table->foreign(['organization_id', 'warehouse_id'], 'inv_bal_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'inv_bal_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
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
            $table->unique(['organization_id', 'id'], 'inv_mov_org_id_unique');
            $table->index(['organization_id', 'created_at'], 'inv_mov_org_date_idx');
            $table->index(['organization_id', 'warehouse_id', 'created_at'], 'inv_mov_wh_date_idx');
            $table->index(['organization_id', 'product_variant_id', 'created_at'], 'inv_mov_variant_date_idx');
            $table->index(['organization_id', 'movement_type'], 'inv_mov_type_idx');
            $table->foreign(['organization_id', 'warehouse_id'], 'inv_mov_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'inv_mov_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
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
            $table->unique(['organization_id', 'id'], 'inv_res_org_id_unique');
            $table->unique(
                ['organization_id', 'warehouse_id', 'product_variant_id', 'reference_type', 'reference_id'],
                'inv_res_reference_unique'
            );
            $table->index(['organization_id', 'status'], 'inv_res_org_status_idx');
            $table->index(['organization_id', 'warehouse_id', 'status'], 'inv_res_wh_status_idx');
            $table->index(['organization_id', 'product_variant_id', 'status'], 'inv_res_variant_status_idx');
            $table->index(['organization_id', 'expires_at'], 'inv_res_expiry_idx');
            $table->foreign(['organization_id', 'warehouse_id'], 'inv_res_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'inv_res_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('warehouses');
    }
};
