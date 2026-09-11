<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier procurement / special-order V1.
 *
 * When company stock cannot cover a customer Sales Order line, a sales employee
 * secures the exact quantity from a supplier for that customer.
 *
 * Tables:
 *
 * - suppliers
 *      Minimal Organization-scoped supplier directory.
 *      This is NOT Accounts Payable.
 *
 * - procurement_sequences
 *      Per-Organization running sequence for APV-000001 numbers.
 *
 * - sales_order_procurements
 *      One row represents one supplier-procured requirement linked to a
 *      SalesOrderLine.
 *
 * Company-owned quantity continues to live in:
 * sales_order_inventory_allocations.
 *
 * Supplier-procured quantity does NOT create Inventory stock/reservation until
 * physical receipt.
 *
 * On receipt:
 *
 * 1. A real immutable InventoryMovement of type supplier_receipt is created.
 * 2. on_hand increases in the receiving Warehouse.
 * 3. The same quantity is immediately allocated/reserved for the SalesOrder.
 *
 * Therefore supplier-procured stock can physically enter the company without
 * temporarily becoming freely sellable stock.
 *
 * Future Finance/Purchasing:
 *
 * supplier_unit_cost
 * supplier_invoice_reference
 *
 * are reserved for a later supplier-accounting phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Suppliers
        |--------------------------------------------------------------------------
        |
        | Minimal supplier directory scoped to one Organization.
        |
        | No Accounts Payable / supplier accounting is implemented here.
        |
        */

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');

            $table->string('contact_person')
                ->nullable();

            $table->string('phone', 64)
                ->nullable();

            $table->string('email')
                ->nullable();

            $table->text('address')
                ->nullable();

            $table->text('notes')
                ->nullable();

            $table->boolean('active')
                ->default(true);

            $table->timestamps();

            /*
             * Required for tenant-safe composite foreign keys such as:
             *
             * (organization_id, supplier_id)
             *      →
             * suppliers(organization_id, id)
             */
            $table->unique(
                ['organization_id', 'id'],
                'supplier_org_id_unique'
            );

            $table->index(
                ['organization_id', 'active'],
                'supplier_org_active_idx'
            );

            $table->index(
                ['organization_id', 'name'],
                'supplier_org_name_idx'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Procurement sequence
        |--------------------------------------------------------------------------
        |
        | Locked per-Organization sequence used to generate:
        |
        | APV-000001
        | APV-000002
        | ...
        |
        */

        Schema::create('procurement_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')
                ->primary();

            $table->unsignedBigInteger('next_number')
                ->default(1);

            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Sales Order Procurements
        |--------------------------------------------------------------------------
        |
        | Represents the supplier-sourced portion of a SalesOrderLine.
        |
        | Important:
        |
        | A ProductVariant is NOT duplicated as another SalesOrderLine.
        |
        | Example:
        |
        | Sales line quantity = 10
        |
        | Company allocations = 4
        | Supplier procurement = 6
        |
        | Commercial line remains ONE line with quantity 10.
        |
        */

        Schema::create('sales_order_procurements', function (Blueprint $table) {
            $table->id();

            /*
             * Tenant boundary.
             *
             * organization_id MUST remain NOT NULL.
             */
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Store owning the commercial transaction.
             */
            $table->unsignedBigInteger('store_id');

            /*
             * Human-readable procurement number:
             *
             * APV-000001
             */
            $table->string('procurement_number', 32);

            /*
             * Business relations.
             */
            $table->unsignedBigInteger('supplier_id');

            $table->unsignedBigInteger('sales_order_id');

            /*
             * Nullable because historical/future workflows may preserve a
             * procurement even if its commercial line relation is intentionally
             * detached by application logic.
             *
             * Composite FK uses RESTRICT, never SET NULL.
             */
            $table->unsignedBigInteger('sales_order_line_id')
                ->nullable();

            $table->unsignedBigInteger('product_variant_id');

            /*
             * Quantity sourced from Supplier.
             *
             * Internal quantity precision remains DECIMAL(19,4).
             */
            $table->decimal('quantity', 19, 4);

            /*
             * Procurement lifecycle:
             *
             * pending_supplier
             * supplier_confirmed
             * ordered
             * received
             * completed
             * cancelled
             * unavailable
             */
            $table->string('status', 24)
                ->default('pending_supplier');

            /*
             * Supplier availability confirmation:
             *
             * pending_confirmation
             * confirmed_available
             * unavailable
             */
            $table->string('supplier_availability_status', 32)
                ->default('pending_confirmation');

            /*
             * Supplier information related specifically to this procurement.
             */
            $table->string('supplier_reference')
                ->nullable();

            $table->date('expected_at')
                ->nullable();

            $table->timestamp('ordered_at')
                ->nullable();

            $table->timestamp('received_at')
                ->nullable();

            /*
             * Warehouse where supplier goods physically arrive.
             *
             * This is set only when receipt occurs.
             *
             * Tenant-safe composite FK is defined below.
             *
             * IMPORTANT:
             * We intentionally use RESTRICT rather than SET NULL because
             * organization_id is NOT NULL and belongs to the composite FK.
             */
            $table->unsignedBigInteger('receiving_warehouse_id')
                ->nullable();

            /*
             * Optional internal TransferRequest.
             *
             * Example:
             *
             * Supplier goods arrive in Depot,
             * but the SalesOrder must ultimately be fulfilled from Showroom.
             *
             * Depot → Showroom then uses the existing TransferRequest workflow.
             *
             * Tenant-safe composite FK uses RESTRICT, not SET NULL.
             */
            $table->unsignedBigInteger('transfer_request_id')
                ->nullable();

            /*
             * Operational notes.
             */
            $table->text('notes')
                ->nullable();

            $table->text('cancellation_reason')
                ->nullable();

            /*
             * Reserved for future Finance / Purchasing.
             *
             * These fields do NOT currently create supplier accounting entries.
             */
            $table->decimal('supplier_unit_cost', 19, 4)
                ->nullable();

            $table->string('supplier_invoice_reference')
                ->nullable();

            /*
             * Actor references.
             *
             * These are SINGLE-COLUMN nullable foreign keys.
             *
             * Therefore nullOnDelete() is valid here.
             */
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('confirmed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('ordered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('received_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Uniques / indexes
            |--------------------------------------------------------------------------
            */

            $table->unique(
                ['organization_id', 'procurement_number'],
                'procurement_number_unique'
            );

            /*
             * Allows tenant-safe composite references to:
             *
             * sales_order_procurements(organization_id, id)
             */
            $table->unique(
                ['organization_id', 'id'],
                'procurement_org_id_unique'
            );

            $table->index(
                ['organization_id', 'status'],
                'procurement_status_idx'
            );

            $table->index(
                ['organization_id', 'sales_order_id'],
                'procurement_order_idx'
            );

            $table->index(
                ['organization_id', 'supplier_id', 'status'],
                'procurement_supplier_status_idx'
            );

            /*
            |--------------------------------------------------------------------------
            | Tenant-safe foreign keys
            |--------------------------------------------------------------------------
            */

            $table->foreign(
                ['organization_id', 'store_id'],
                'procurement_store_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('stores')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'supplier_id'],
                'procurement_supplier_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('suppliers')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'sales_order_id'],
                'procurement_order_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('sales_orders')
                ->restrictOnDelete();

            /*
             * sales_order_line_id is nullable.
             *
             * DO NOT use nullOnDelete() on this composite FK.
             *
             * MySQL would attempt to NULL BOTH:
             *
             * organization_id
             * sales_order_line_id
             *
             * but organization_id is NOT NULL.
             */
            $table->foreign(
                ['organization_id', 'sales_order_line_id'],
                'procurement_order_line_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('sales_order_lines')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'product_variant_id'],
                'procurement_variant_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('product_variants')
                ->restrictOnDelete();

            /*
             * IMPORTANT FIX:
             *
             * Previous version used:
             *
             * foreign(receiving_warehouse_id)
             *     -> nullOnDelete()
             *
             * That worked technically as a single FK, but it did not enforce
             * Organization isolation at the database level.
             *
             * This version uses:
             *
             * (organization_id, receiving_warehouse_id)
             *
             * and RESTRICT.
             */
            $table->foreign(
                ['organization_id', 'receiving_warehouse_id'],
                'procurement_receiving_wh_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('warehouses')
                ->restrictOnDelete();

            /*
             * IMPORTANT MYSQL 1830 FIX:
             *
             * NEVER:
             *
             * (organization_id, transfer_request_id)
             *     -> nullOnDelete()
             *
             * MySQL ON DELETE SET NULL applies to the whole composite key and
             * therefore attempts to set organization_id = NULL.
             *
             * organization_id is intentionally NOT NULL.
             *
             * Application code may explicitly clear transfer_request_id first
             * if a future business workflow legitimately needs to detach it.
             */
            $table->foreign(
                ['organization_id', 'transfer_request_id'],
                'procurement_transfer_request_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('transfer_requests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        /*
         * Drop dependent tables first.
         */
        Schema::dropIfExists('sales_order_procurements');

        Schema::dropIfExists('procurement_sequences');

        Schema::dropIfExists('suppliers');
    }
};