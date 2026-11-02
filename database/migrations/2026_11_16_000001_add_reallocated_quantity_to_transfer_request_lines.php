<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-priority reconciliation of minimum replenishment.
 *
 * `reallocated_quantity` is the part of a customer-linked request's
 * MINIMUM_REPLENISHMENT line that was MOVED from an open general replenishment
 * request on the same route when the order was confirmed (it is
 * already included in `quantity`; it is not extra demand). It only drives the
 * quantity breakdown shown to the warehouse team. The full trail of each move
 * (source request, old/new quantities, order) lives in audit_logs.
 *
 * No inventory effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_request_lines', function (Blueprint $table) {
            $table->decimal('reallocated_quantity', 19, 4)->nullable()->after('requested_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_request_lines', function (Blueprint $table) {
            $table->dropColumn('reallocated_quantity');
        });
    }
};
