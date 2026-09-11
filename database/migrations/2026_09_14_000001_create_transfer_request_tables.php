<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal warehouse transfer REQUEST layer (logistics instruction, not a stock
 * movement).
 *
 * `stock_transfers` in this codebase only ever represents a COMPLETED physical
 * movement (its lines require the paired inventory_movement ids). This adds the
 * smallest separate lifecycle layer on top:
 *
 *   requested → preparing → shipped → received   (+ cancelled)
 *
 * A `transfer_request` carries NO inventory effect on creation. It reserves
 * nothing extra — the customer Order's own reservation already sits at the
 * source warehouse. Only when a request is RECEIVED does it call the existing
 * CreateStockTransferAction to produce the immutable ledger movement, and it
 * links back through `stock_transfer_id`.
 *
 * A request may consolidate demand from several reasons for one source →
 * destination pair (one ORDER_FULFILLMENT line + one MINIMUM_REPLENISHMENT line
 * for the same variant), so the "why 7?" breakdown is always visible.
 *
 * Warehouse FKs follow the sibling `stock_transfers` shape (single-column,
 * RESTRICT). Composite tenant FKs on nullable columns use RESTRICT (never SET
 * NULL — organization_id is NOT NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_request_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('request_number', 32);
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();

            // requested | preparing | shipped | received | cancelled
            $table->string('status', 24)->default('requested');

            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('stock_transfer_id')->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'request_number'], 'transfer_request_number_unique');
            $table->unique(['organization_id', 'id'], 'transfer_request_org_id_unique');
            // Idempotency: at most one request per (Order, source warehouse). A
            // retried confirmation reuses the existing row instead of creating a
            // duplicate. NULL sales_order_id (pure replenishment) is exempt.
            $table->unique(['organization_id', 'sales_order_id', 'source_warehouse_id'], 'transfer_request_order_source_unique');
            $table->index(['organization_id', 'destination_warehouse_id', 'status'], 'transfer_request_dest_status_idx');
            $table->index(['organization_id', 'status'], 'transfer_request_status_idx');

            $table->foreign(['organization_id', 'sales_order_id'], 'transfer_request_order_fk')
                ->references(['organization_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['organization_id', 'stock_transfer_id'], 'transfer_request_stock_transfer_fk')
                ->references(['organization_id', 'id'])->on('stock_transfers')->restrictOnDelete();
        });

        Schema::create('transfer_request_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('transfer_request_id')->constrained('transfer_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 19, 4);

            // order_fulfillment | minimum_replenishment | manual
            $table->string('reason', 32);

            $table->unsignedBigInteger('sales_order_line_id')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'transfer_request_line_org_id_unique');
            $table->unique(['organization_id', 'transfer_request_id', 'product_variant_id', 'reason'], 'transfer_request_line_identity_unique');
            $table->index(['organization_id', 'transfer_request_id'], 'transfer_request_line_request_idx');

            $table->foreign(['organization_id', 'product_variant_id'], 'transfer_request_line_variant_fk')
                ->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['organization_id', 'sales_order_line_id'], 'transfer_request_line_order_line_fk')
                ->references(['organization_id', 'id'])->on('sales_order_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_request_lines');
        Schema::dropIfExists('transfer_requests');
        Schema::dropIfExists('transfer_request_sequences');
    }
};
