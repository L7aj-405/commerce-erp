<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1.3 post-shipment customer follow-up context.
 *
 * A manually confirmed follow-up may point at the successful F1.2 share that
 * proposed it. The unique source notification is the durable duplicate guard:
 * retrying the share/create transport returns the same work item, while a
 * later explicit follow-up (without a source notification) remains allowed.
 * Shipment outcomes are operational metadata only; they never drive stock,
 * invoice, delivery-note or payment state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->ulid('fulfillment_id')->nullable()->after('sales_order_id');
            $table->unsignedBigInteger('source_notification_id')->nullable()->after('fulfillment_id');
            $table->string('shipment_outcome', 32)->nullable()->after('outcome_note');
            $table->timestamp('shipment_outcome_at')->nullable()->after('shipment_outcome');

            $table->index(['organization_id', 'fulfillment_id'], 'follow_up_fulfillment_idx');
            $table->unique('source_notification_id', 'follow_up_share_notif_uq');
            $table->foreign('fulfillment_id', 'follow_up_fulfillment_fk')
                ->references('id')->on('fulfillments')->nullOnDelete();
            $table->foreign('source_notification_id', 'follow_up_share_notif_fk')
                ->references('id')->on('fulfillment_customer_notifications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign('follow_up_share_notif_fk');
            $table->dropForeign('follow_up_fulfillment_fk');
            $table->dropUnique('follow_up_share_notif_uq');
            $table->dropIndex('follow_up_fulfillment_idx');
            $table->dropColumn(['fulfillment_id', 'source_notification_id', 'shipment_outcome', 'shipment_outcome_at']);
        });
    }
};
