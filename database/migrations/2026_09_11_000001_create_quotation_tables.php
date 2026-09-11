<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation / Devis domain — V1.
 *
 * A Devis is a commercial proposal, never a sale. It reserves nothing,
 * consumes nothing, and contributes nothing to Finance.
 *
 * It snapshots enough seller + customer + line data to stay historically
 * reproducible, exactly like an Invoice, but keeps its OWN annual sequence
 * (`DEV-N/YYYY`).
 *
 * `non_stock_items` is an organisation-scoped library of manually quoted
 * articles that do NOT exist in the Product catalogue.
 *
 * They carry:
 * - no InventoryBalance
 * - no warehouse stock
 * - no reservations
 *
 * They are commercial catalogue candidates only.
 *
 * IMPORTANT TENANT FK RULE:
 *
 * Composite tenant foreign keys such as:
 *
 *     (organization_id, nullable_fk)
 *
 * MUST NOT use ON DELETE SET NULL because organization_id is NOT NULL.
 *
 * Therefore nullable composite references use RESTRICT / NO ACTION.
 * If the optional reference must later be cleared, application code must
 * explicitly set only the nullable child column to NULL before deleting
 * the referenced record.
 *
 * Appended after 2026_08_23_000010 / 2026_09_10_000001
 * per MIGRATION_BASELINE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Quotation Sequences
        |--------------------------------------------------------------------------
        |
        | Independent annual numbering for Devis.
        |
        | Example:
        | DEV-1/2026
        | DEV-2/2026
        |
        | One counter per organization + year.
        |
        */
        Schema::create('quotation_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);

            $table->timestamps();

            $table->primary(
                ['organization_id', 'year'],
                'quotation_sequences_primary'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Non-stock Items
        |--------------------------------------------------------------------------
        |
        | Reusable organisation-scoped library of articles manually entered
        | from quotations but not yet present in the real Product catalogue.
        |
        | These records NEVER represent physical stock.
        |
        */
        Schema::create('non_stock_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('organization_id');

            $table->string('name');
            $table->string('reference')->nullable();
            $table->string('unit_label')->nullable();

            /*
             * Indicates how the employee originally entered the price.
             *
             * Values:
             * - ht
             * - ttc
             */
            $table->string('price_input_mode', 8)->default('ht');

            $table->decimal(
                'default_price_excl_tax',
                19,
                4
            )->nullable();

            $table->decimal(
                'default_price_incl_tax',
                19,
                4
            )->nullable();

            /*
             * Optional current tax configuration reference.
             *
             * Historical quotation lines snapshot tax_name + tax_rate,
             * therefore changing this later does not change old documents.
             */
            $table->unsignedBigInteger('tax_rate_id')->nullable();

            $table->string('tax_name')->nullable();

            $table->decimal(
                'tax_rate',
                7,
                4
            )->default(0);

            /*
             * active | inactive
             */
            $table->string('status', 16)->default('active');

            /*
             * Number of quotations where this reusable item was selected.
             */
            $table->unsignedInteger('usage_count')->default(0);

            /*
             * User references are NOT composite tenant FKs.
             * nullOnDelete() is valid here because only this single nullable
             * column is affected.
             */
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Optional link created if this non-stock item is later promoted
             * into a real ProductVariant.
             */
            $table->unsignedBigInteger(
                'promoted_product_variant_id'
            )->nullable();

            $table->timestamps();

            /*
             * Required for composite tenant foreign keys pointing to:
             *
             * (organization_id, id)
             */
            $table->unique(
                ['organization_id', 'id'],
                'nonstock_org_id_unique'
            );

            $table->unique(
                ['organization_id', 'name'],
                'nonstock_org_name_unique'
            );

            $table->index(
                ['organization_id', 'status'],
                'nonstock_org_status_idx'
            );

            /*
             * Organization owns the reusable non-stock item.
             */
            $table->foreign(
                'organization_id',
                'nonstock_org_fk'
            )
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            /*
             * IMPORTANT:
             *
             * tax_rate_id is nullable, but organization_id is NOT NULL.
             *
             * Therefore this composite FK CANNOT use SET NULL.
             *
             * If a TaxRate needs to be deleted, application code must first:
             *
             * tax_rate_id = NULL
             *
             * for referencing non_stock_items.
             */
            $table->foreign(
                ['organization_id', 'tax_rate_id'],
                'nonstock_tax_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('tax_rates')
                ->restrictOnDelete();

            /*
             * Same rule:
             *
             * promoted_product_variant_id is nullable,
             * organization_id is NOT NULL,
             * therefore no nullOnDelete() on this composite FK.
             */
            $table->foreign(
                ['organization_id', 'promoted_product_variant_id'],
                'nonstock_promoted_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('product_variants')
                ->restrictOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Quotations
        |--------------------------------------------------------------------------
        |
        | Main Devis document.
        |
        | A quotation is independent from:
        | - Inventory
        | - Payments
        | - Invoice accounting
        |
        | until explicitly converted into a SalesOrder.
        |
        */
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');

            /*
             * Optional source Customer.
             *
             * The Quotation also stores an immutable customer snapshot below.
             */
            $table->unsignedBigInteger('customer_id')->nullable();

            /*
             * NULL while Draft.
             *
             * Official number allocated only at issuance.
             */
            $table->string(
                'quotation_number',
                64
            )->nullable();

            /*
             * Expected values:
             *
             * draft
             * issued
             * accepted
             * rejected
             * expired
             * converted
             */
            $table->string(
                'status',
                24
            )->default('draft');

            $table->char('currency_code', 3);

            $table->date('quotation_date');
            $table->date('valid_until')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Customer snapshot
            |--------------------------------------------------------------------------
            |
            | Historical Devis must not change when the Customer record changes.
            |
            */
            $table->string('customer_name')->nullable();
            $table->string('customer_company')->nullable();
            $table->string('customer_email')->nullable();

            $table->string(
                'customer_phone',
                64
            )->nullable();

            $table->string(
                'customer_tax_identifier',
                128
            )->nullable();

            $table->text('billing_address')->nullable();

            $table->string(
                'representative_name'
            )->nullable();

            /*
             * Immutable company/document identity snapshot.
             */
            $table->json('seller_snapshot')->nullable();

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->string(
                'template_version',
                32
            )->default('v1');

            /*
            |--------------------------------------------------------------------------
            | Authoritative financial snapshots
            |--------------------------------------------------------------------------
            */
            $table->decimal(
                'subtotal_excl_tax',
                19,
                4
            )->default(0);

            $table->decimal(
                'discount_total',
                19,
                4
            )->default(0);

            $table->decimal(
                'tax_total',
                19,
                4
            )->default(0);

            $table->decimal(
                'total_incl_tax',
                19,
                4
            )->default(0);

            /*
            |--------------------------------------------------------------------------
            | Lifecycle / Audit actors
            |--------------------------------------------------------------------------
            */
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('issued_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('issued_at')->nullable();

            $table->dateTime('accepted_at')->nullable();

            $table->foreignId('accepted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('rejected_at')->nullable();

            $table->foreignId('rejected_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('rejection_reason')->nullable();

            $table->dateTime('converted_at')->nullable();

            /*
             * SalesOrder created when quotation is converted.
             */
            $table->unsignedBigInteger(
                'converted_sales_order_id'
            )->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            /*
             * Supports tenant composite FKs referencing quotations.
             */
            $table->unique(
                ['organization_id', 'id'],
                'quotation_org_id_unique'
            );

            /*
             * MySQL allows multiple NULL quotation_number values,
             * therefore Draft quotations can coexist without numbers.
             */
            $table->unique(
                ['organization_id', 'quotation_number'],
                'quotation_org_number_unique'
            );

            $table->index(
                ['organization_id', 'store_id', 'status'],
                'quotation_store_status_idx'
            );

            $table->index(
                ['organization_id', 'store_id', 'quotation_date'],
                'quotation_store_date_idx'
            );

            $table->index(
                ['organization_id', 'customer_id'],
                'quotation_customer_idx'
            );

            /*
            |--------------------------------------------------------------------------
            | Tenant-safe Foreign Keys
            |--------------------------------------------------------------------------
            */

            $table->foreign(
                ['organization_id', 'store_id'],
                'quotation_store_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('stores')
                ->restrictOnDelete();

            /*
             * customer_id is nullable, but because this is a composite FK with
             * organization_id NOT NULL, SET NULL cannot be used safely.
             *
             * Historical quotations should normally prevent Customer deletion
             * anyway because they contain an immutable snapshot.
             */
            $table->foreign(
                ['organization_id', 'customer_id'],
                'quotation_customer_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('customers')
                ->restrictOnDelete();

            /*
             * converted_sales_order_id is optional.
             *
             * Again:
             * (organization_id, nullable FK)
             * must NOT use ON DELETE SET NULL.
             */
            $table->foreign(
                ['organization_id', 'converted_sales_order_id'],
                'quotation_order_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('sales_orders')
                ->restrictOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Quotation Lines
        |--------------------------------------------------------------------------
        |
        | Every line is a financial/document snapshot.
        |
        | line_type:
        | - catalog
        | - non_stock
        |
        */
        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('quotation_id');

            /*
             * Exactly one source is normally present according to line_type:
             *
             * catalog:
             * product_variant_id
             *
             * non_stock:
             * non_stock_item_id
             */
            $table->unsignedBigInteger(
                'product_variant_id'
            )->nullable();

            $table->unsignedBigInteger(
                'non_stock_item_id'
            )->nullable();

            $table->unsignedInteger('position');

            /*
             * catalog | non_stock
             */
            $table->string('line_type', 24);

            /*
            |--------------------------------------------------------------------------
            | Product/document snapshot
            |--------------------------------------------------------------------------
            */
            $table->string('description');
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('reference')->nullable();
            $table->string('unit_label')->nullable();

            /*
             * ht | ttc
             *
             * Records how the employee entered the commercial price.
             */
            $table->string(
                'price_input_mode',
                8
            )->default('ht');

            /*
            |--------------------------------------------------------------------------
            | Quantity and Price snapshots
            |--------------------------------------------------------------------------
            */
            $table->decimal(
                'quantity',
                19,
                4
            );

            $table->decimal(
                'unit_price_excl_tax',
                19,
                4
            );

            $table->decimal(
                'unit_price_incl_tax',
                19,
                4
            )->default(0);

            /*
            |--------------------------------------------------------------------------
            | Tax snapshot
            |--------------------------------------------------------------------------
            */
            $table->string('tax_name')->nullable();

            $table->decimal(
                'tax_rate',
                7,
                4
            )->default(0);

            /*
            |--------------------------------------------------------------------------
            | Discount
            |--------------------------------------------------------------------------
            |
            | Expected:
            | none
            | fixed
            | percentage
            |
            */
            $table->string(
                'discount_type',
                24
            )->default('none');

            $table->decimal(
                'discount_value',
                19,
                4
            )->default(0);

            /*
            |--------------------------------------------------------------------------
            | Authoritative calculated snapshots
            |--------------------------------------------------------------------------
            |
            | Never trust these values from React/client requests.
            |
            */
            $table->decimal(
                'subtotal_excl_tax',
                19,
                4
            );

            $table->decimal(
                'discount_amount',
                19,
                4
            );

            $table->decimal(
                'taxable_amount',
                19,
                4
            );

            $table->decimal(
                'tax_amount',
                19,
                4
            );

            $table->decimal(
                'total_incl_tax',
                19,
                4
            );

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->unique(
                ['organization_id', 'id'],
                'quotation_line_org_id_unique'
            );

            $table->unique(
                ['organization_id', 'quotation_id', 'position'],
                'quotation_line_position_unique'
            );

            $table->index(
                ['organization_id', 'product_variant_id'],
                'quotation_line_variant_idx'
            );

            /*
            |--------------------------------------------------------------------------
            | Tenant-safe Foreign Keys
            |--------------------------------------------------------------------------
            */

            $table->foreign(
                ['organization_id', 'quotation_id'],
                'quotation_line_quotation_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('quotations')
                ->restrictOnDelete();

            /*
             * product_variant_id may be NULL for non-stock lines.
             *
             * RESTRICT is intentional:
             * historical / draft document references should not silently lose
             * tenant-scoped catalogue identity through SET NULL.
             */
            $table->foreign(
                ['organization_id', 'product_variant_id'],
                'quotation_line_variant_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('product_variants')
                ->restrictOnDelete();

            /*
             * non_stock_item_id is nullable, but this is a composite FK with
             * organization_id NOT NULL.
             *
             * Therefore nullOnDelete() is invalid in MySQL.
             */
            $table->foreign(
                ['organization_id', 'non_stock_item_id'],
                'quotation_line_nonstock_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('non_stock_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        /*
         * Reverse dependency order.
         */
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('non_stock_items');
        Schema::dropIfExists('quotation_sequences');
    }
};