<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order fulfillment origin for POS orders, independent of the delivery
 * method (`pos_fulfillment_mode` pickup / delivery):
 *
 *   showroom — every customer unit is gathered at the POS warehouse first
 *              (remote reservations travel on a protected customer transfer);
 *   direct   — units leave from the warehouse that holds their reservation.
 *
 * Additive and nullable: existing rows stay NULL and keep their historical
 * effective behaviour (pickup → showroom, delivery → direct), see
 * {@see \App\Models\SalesOrder::fulfillmentOrigin()}. No data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('pos_fulfillment_origin', 16)->nullable()->after('pos_fulfillment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('pos_fulfillment_origin');
        });
    }
};
