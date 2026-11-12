<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — isolated Test fulfillment / shipment / customer hand-over ledger.
 *
 * Physically separate mirrors of the Production fulfillment tables
 * (shipments, their items, proofs and customer notifications). The fulfillment
 * models route to them only while a Test context is active, so a Production
 * shipment, its reports or its numbering can never see — or be changed by — a
 * Test shipment. Carrier contacts, products, stores, warehouses and users stay
 * shared master data (referenced, never copied).
 *
 * Internal references are composite (sandbox_id, id) foreign keys: an item,
 * proof or notification can only belong to a Test shipment of the same
 * sandbox. Links to sales orders (a single table carrying sandbox_id) are
 * checked by the model guard. Constraint names are explicit and short (MySQL
 * 64-character identifier limit).
 */
return new class extends Migration
{
    private const TABLES = [
        'sandbox_fulfillment_customer_notifications', 'sandbox_fulfillment_attachments',
        'sandbox_fulfillment_items', 'sandbox_fulfillments',
    ];

    public function up(): void
    {
        Schema::create('sandbox_fulfillments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('sandbox_id');
            $table->foreign('sandbox_id', 'sbx_ful_sandbox_fk')->references('id')->on('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->string('status', 24);
            $table->string('workflow_mode', 16);
            $table->string('channel', 16)->default('carrier');
            $table->uuid('client_operation_id')->nullable();

            $table->unsignedBigInteger('carrier_contact_id')->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->string('tracking_url', 500)->nullable();
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

            $table->unique(['sandbox_id', 'id'], 'sbx_ful_sbx_id_uq');
            $table->unique(['sandbox_id', 'organization_id', 'client_operation_id'], 'sbx_ful_client_op_uq');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_id', 'status'], 'sbx_ful_order_status_idx');
            $table->index(['sandbox_id', 'organization_id', 'store_id', 'status'], 'sbx_ful_store_status_idx');
            $table->index(['organization_id', 'carrier_contact_id'], 'sbx_ful_carrier_idx');

            $table->foreign(['organization_id', 'store_id'], 'sbx_ful_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'sbx_ful_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            foreach (['prepared_by_user_id', 'handed_by_user_id', 'delivered_by_user_id', 'created_by_user_id'] as $i => $column) {
                $table->foreign($column, 'sbx_ful_u'.($i + 1).'_fk')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::create('sandbox_fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sandbox_id');
            $table->foreign('sandbox_id', 'sbx_fitem_sandbox_fk')->references('id')->on('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->ulid('fulfillment_id');
            $table->unsignedBigInteger('sales_order_line_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('quantity', 19, 4);
            $table->timestamps();

            $table->unique(['sandbox_id', 'id'], 'sbx_fitem_sbx_id_uq');
            $table->unique(['fulfillment_id', 'sales_order_line_id'], 'sbx_fitem_line_uq');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_line_id'], 'sbx_fitem_line_idx');

            $table->foreign(['sandbox_id', 'fulfillment_id'], 'sbx_fitem_ful_fk')->references(['sandbox_id', 'id'])->on('sandbox_fulfillments')->cascadeOnDelete();
            $table->foreign(['organization_id', 'sales_order_line_id'], 'sbx_fitem_line_fk')->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'sbx_fitem_var_fk')->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('sandbox_fulfillment_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('sandbox_id');
            $table->foreign('sandbox_id', 'sbx_fatt_sandbox_fk')->references('id')->on('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->ulid('fulfillment_id');
            $table->string('category', 24);
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['sandbox_id', 'organization_id', 'fulfillment_id'], 'sbx_fatt_ful_idx');
            $table->foreign(['sandbox_id', 'fulfillment_id'], 'sbx_fatt_ful_fk')->references(['sandbox_id', 'id'])->on('sandbox_fulfillments')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'sbx_fatt_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('sandbox_fulfillment_customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sandbox_id');
            $table->foreign('sandbox_id', 'sbx_fnot_sandbox_fk')->references('id')->on('sandboxes')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->ulid('fulfillment_id');
            $table->string('event', 32);
            $table->string('origin', 16)->default('automatic');
            $table->string('dedupe_key', 80);
            $table->string('channel', 16)->default('email');
            $table->string('status', 16);
            $table->string('recipient', 255)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->text('message')->nullable();
            $table->json('attachment_ids')->nullable();
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->string('skip_reason', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['sandbox_id', 'fulfillment_id', 'dedupe_key'], 'sbx_fnot_dedupe_uq');
            $table->index(['sandbox_id', 'organization_id', 'sales_order_id'], 'sbx_fnot_order_idx');

            $table->foreign('sent_by_user_id', 'sbx_fnot_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['sandbox_id', 'fulfillment_id'], 'sbx_fnot_ful_fk')->references(['sandbox_id', 'id'])->on('sandbox_fulfillments')->cascadeOnDelete();
            $table->foreign(['organization_id', 'sales_order_id'], 'sbx_fnot_order_fk')->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Dropping these tables would silently erase Test shipments whose stock
        // effects remain on the Test inventory ledger.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove the Test fulfillment ledger while Test fulfillment records exist.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
