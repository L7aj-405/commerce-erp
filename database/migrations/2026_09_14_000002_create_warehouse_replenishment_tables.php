<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Showroom / operational-warehouse minimum-stock replenishment POLICY.
 *
 * This is NOT product stock. It only drives automatic internal transfer
 * requests so an operational warehouse (e.g. the POS Showroom) does not sit at
 * zero while the same organisation holds transferable stock elsewhere.
 *
 * Precedence when resolving a minimum for (warehouse, variant):
 *   1. warehouse_replenishment_overrides.minimum_quantity   (per variant)
 *   2. warehouse_replenishment_settings.default_minimum_quantity (when auto_replenish = true)
 *   3. no minimum / disabled
 *
 * Composite tenant FKs on the (organization_id, *) pairs, RESTRICT on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_replenishment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->boolean('auto_replenish')->default(false);
            $table->decimal('default_minimum_quantity', 19, 4)->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'warehouse_id'], 'wh_replenish_setting_unique');

            $table->foreign('organization_id', 'wh_replenish_setting_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'wh_replenish_setting_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->cascadeOnDelete();
        });

        Schema::create('warehouse_replenishment_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('minimum_quantity', 19, 4);
            $table->timestamps();

            $table->unique(['organization_id', 'warehouse_id', 'product_variant_id'], 'wh_replenish_override_unique');
            $table->index(['organization_id', 'warehouse_id'], 'wh_replenish_override_wh_idx');

            $table->foreign('organization_id', 'wh_replenish_override_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'warehouse_id'], 'wh_replenish_override_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'wh_replenish_override_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_replenishment_overrides');
        Schema::dropIfExists('warehouse_replenishment_settings');
    }
};
