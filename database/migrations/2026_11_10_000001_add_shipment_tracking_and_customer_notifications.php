<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1.2 Shipment tracking & delivery lifecycle (logistics only — stock already
 * left at hand-over, nothing here moves it).
 *
 * - fulfillments.tracking_url: the carrier's public tracking page, if any.
 * - fulfillment_customer_notifications: every message to the customer about a
 *   shipment. dedupe_key is the persistent idempotency key:
 *   - automatic ("auto:<event>"): one per (shipment, event) across
 *     double-clicks, retries and several queue workers. Created in the
 *     transition's transaction; a queued job claims it (pending → sending
 *     under lock) and records sent / skipped / failed.
 *   - manual "Partager au client" ("share:<client operation id>"): one per
 *     operator submit, so a retried request never sends twice.
 *   content_hash fingerprints channel + recipient + shared facts, so the same
 *   message is not emailed twice by the two paths without an explicit resend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillments', function (Blueprint $table) {
            $table->string('tracking_url', 500)->nullable()->after('tracking_number');
        });

        Schema::create('fulfillment_customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->ulid('fulfillment_id');
            // handed_to_carrier | delivered
            $table->string('event', 32);
            // automatic | manual
            $table->string('origin', 16)->default('automatic');
            $table->string('dedupe_key', 80);
            // email | whatsapp
            $table->string('channel', 16)->default('email');
            // pending | sending | sent | skipped | failed | opened (WhatsApp link opened by the operator)
            $table->string('status', 16);
            $table->string('recipient', 255)->nullable();
            $table->char('content_hash', 64)->nullable();
            // The exact text shared (manual shares), kept for the timeline.
            $table->text('message')->nullable();
            $table->json('attachment_ids')->nullable();
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            // disabled | no_customer_email | mail_not_configured | already_shared
            $table->string('skip_reason', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'fulfillment_id', 'dedupe_key'], 'ful_notif_dedupe_uq');
            $table->index(['organization_id', 'fulfillment_id', 'content_hash'], 'ful_notif_content_idx');
            $table->index(['organization_id', 'sales_order_id'], 'ful_notif_order_idx');
            $table->index(['organization_id', 'status', 'created_at'], 'ful_notif_status_idx');

            $table->foreign('organization_id', 'ful_notif_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('sent_by_user_id', 'ful_notif_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['organization_id', 'fulfillment_id'], 'ful_notif_ful_fk')->references(['organization_id', 'id'])->on('fulfillments')->cascadeOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'ful_notif_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_customer_notifications');
        Schema::table('fulfillments', function (Blueprint $table) {
            $table->dropColumn('tracking_url');
        });
    }
};
