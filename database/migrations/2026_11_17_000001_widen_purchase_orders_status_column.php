<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_orders.status was created as VARCHAR(16) for draft | ordered |
 * cancelled. The receipt flow (2026_11_07) added PurchaseOrderStatus
 * `partially_received` (18 chars), which MySQL strict mode rejects with
 * "Data too long for column 'status'" (SQLite ignores VARCHAR lengths, so the
 * test suite never saw it). Widen to 24, the width already used by
 * sales_order_procurements.status for the same lifecycle values.
 *
 * No business-state change; existing values are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('status', 24)->change();
        });
    }

    public function down(): void
    {
        // Fails loudly under strict mode (rather than truncating) if any
        // partially_received purchase order exists.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('status', 16)->change();
        });
    }
};
