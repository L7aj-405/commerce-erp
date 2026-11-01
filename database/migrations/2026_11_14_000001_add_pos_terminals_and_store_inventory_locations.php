<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS terminals and the Store ↔ inventory-location relationship.
 *
 * - sales_orders.pos_terminal_id: the POS terminal (browser/device, a UUID kept
 *   in an encrypted cookie) whose ACTIVE cart this draft is. Each terminal of a
 *   Store has its own active cart; held carts stay resumable by any terminal of
 *   the Store. NULL = a cart created before terminals existed (adopted by the
 *   first terminal that opens the POS of that Store).
 *
 * - stores.default_warehouse_id: the Store's primary inventory location for
 *   the POS. A convenience default, never a stock ledger.
 *
 * - warehouses.store_id: the Store this inventory location physically belongs
 *   to (its showroom / back room). NULL = an organisation-level storage depot.
 *   warehouses.allow_direct_sales: such a depot may exceptionally be chosen as
 *   a direct POS selling location. Stock stays on warehouses — one ledger.
 *
 * Additive and nullable: existing rows keep working unchanged. Composite tenant
 * FKs are RESTRICT (never SET NULL on (organization_id, x)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->uuid('pos_terminal_id')->nullable()->after('pos_warehouse_id');
            $table->index(['organization_id', 'store_id', 'pos_terminal_id'], 'so_pos_terminal_idx');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('organization_id');
            $table->boolean('allow_direct_sales')->default(false)->after('status');
            $table->index(['organization_id', 'store_id'], 'wh_org_store_idx');
            $table->foreign(['organization_id', 'store_id'], 'wh_store_fk')
                ->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedBigInteger('default_warehouse_id')->nullable()->after('default_tax_rate_id');
            $table->index(['organization_id', 'default_warehouse_id'], 'stores_default_wh_idx');
            $table->foreign(['organization_id', 'default_warehouse_id'], 'stores_default_wh_fk')
                ->references(['organization_id', 'id'])->on('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign('stores_default_wh_fk');
            $table->dropIndex('stores_default_wh_idx');
            $table->dropColumn('default_warehouse_id');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropForeign('wh_store_fk');
            $table->dropIndex('wh_org_store_idx');
            $table->dropColumn(['store_id', 'allow_direct_sales']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('so_pos_terminal_idx');
            $table->dropColumn('pos_terminal_id');
        });
    }
};
