<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1.1 Fulfillment / Expédition core: the internal operational record of goods
 * leaving the company for a confirmed Sales Order. Distinct from the Bon de
 * livraison (commercial document) and from the carrier's own documents.
 *
 * - fulfillments: one shipment (or direct hand-over) of a Sales Order. ULID
 *   ids: no enumerable public identifiers. Transport fields live on the row
 *   (1:1, no separate table needed). `workflow_mode` snapshots the
 *   organization's fulfillment mode when the shipment was created.
 * - fulfillment_items: explicit Sales Order line quantities. The same line may
 *   appear in several fulfillments; the canonical actions keep the total at or
 *   below the line quantity under a Sales Order row lock.
 * - fulfillment_attachments: shipping proofs / carrier documents stored on a
 *   private disk (never a public path).
 *
 * Stock leaves only when a fulfillment is handed over (carrier or direct), via
 * InventoryReservationManager::consumeQuantity — one movement per reservation
 * portion, never twice (status checked under lock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            // preparing | ready | handed_to_carrier | in_transit | delivered | closed | cancelled
            $table->string('status', 24);
            // simple | advanced (organization setting when created)
            $table->string('workflow_mode', 16);
            // carrier = handed to a transport contact; direct = handed over at the counter / with the Bon de livraison
            $table->string('channel', 16)->default('carrier');
            // Client-generated id of the submit that created it: a retry returns the same fulfillment.
            $table->uuid('client_operation_id')->nullable();

            $table->unsignedBigInteger('carrier_contact_id')->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->string('carrier_reference', 128)->nullable();
            $table->unsignedSmallInteger('package_count')->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamp('preparation_started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('handed_to_carrier_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('stock_consumed_at')->nullable();

            $table->unsignedBigInteger('prepared_by_user_id')->nullable();
            $table->unsignedBigInteger('handed_by_user_id')->nullable();
            $table->unsignedBigInteger('delivered_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'ful_org_id_uq');
            $table->unique(['organization_id', 'client_operation_id'], 'ful_client_op_uq');
            $table->index(['organization_id', 'sales_order_id', 'status'], 'ful_order_status_idx');
            $table->index(['organization_id', 'store_id', 'status'], 'ful_store_status_idx');
            $table->index(['organization_id', 'carrier_contact_id'], 'ful_carrier_idx');

            $table->foreign('organization_id', 'ful_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'ful_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'ful_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            foreach (['prepared_by_user_id', 'handed_by_user_id', 'delivered_by_user_id', 'created_by_user_id'] as $column) {
                $table->foreign($column, 'ful_'.str_replace('_user_id', '', $column).'_fk')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::create('fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->ulid('fulfillment_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('quantity', 19, 4);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'ful_item_org_id_uq');
            $table->unique(['fulfillment_id', 'sales_order_line_id'], 'ful_item_line_uq');
            $table->index(['organization_id', 'sales_order_line_id'], 'ful_item_line_idx');

            $table->foreign(['organization_id', 'fulfillment_id'], 'ful_item_ful_fk')->references(['organization_id', 'id'])->on('fulfillments')->cascadeOnDelete();
            $table->foreign(['organization_id', 'sales_order_line_id'], 'ful_item_line_fk')->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'ful_item_variant_fk')->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('fulfillment_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->ulid('fulfillment_id');
            // shipping_proof | carrier_document | other
            $table->string('category', 24);
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'fulfillment_id'], 'ful_att_ful_idx');
            $table->foreign(['organization_id', 'fulfillment_id'], 'ful_att_ful_fk')->references(['organization_id', 'id'])->on('fulfillments')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'ful_att_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_attachments');
        Schema::dropIfExists('fulfillment_items');
        Schema::dropIfExists('fulfillments');
    }
};
