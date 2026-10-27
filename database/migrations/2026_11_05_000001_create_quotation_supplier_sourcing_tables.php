<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation supplier sourcing (availability BEFORE a Devis is issued).
 *
 * Tables:
 *
 * - product_suppliers
 *      Organization-scoped "known suppliers" of a catalogue Product. Used to
 *      propose suppliers when a Devis line needs sourcing. Many-to-many: a
 *      Product may have several suppliers, a supplier many Products.
 *
 * - quotation_supplier_availability_requests
 *      Internal tracking record: "someone must ask supplier X whether they can
 *      supply N units for THIS Devis line". NOT a purchase order, sends nothing,
 *      reserves nothing. The IssueQuotationAction gate reads it.
 *
 *      product_variant_id / non_stock_item_id snapshot the article the request
 *      was made for, so a later article swap on the same line never lets an old
 *      confirmation count for a different article.
 *
 * Non-destructive: new tables only. Every composite tenant FK uses RESTRICT —
 * never SET NULL on (organization_id, x) because organization_id is NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');

            $table->unsignedBigInteger('supplier_id');

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['organization_id', 'product_id', 'supplier_id'],
                'product_supplier_unique'
            );

            $table->index(
                ['organization_id', 'supplier_id'],
                'product_supplier_supplier_idx'
            );

            $table->foreign(
                ['organization_id', 'product_id'],
                'product_supplier_product_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('products')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'supplier_id'],
                'product_supplier_supplier_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('suppliers')
                ->restrictOnDelete();
        });

        Schema::create('quotation_supplier_availability_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('store_id');

            $table->unsignedBigInteger('quotation_id');

            $table->unsignedBigInteger('quotation_line_id');

            /*
             * Nullable only for a manual confirmation recorded at issue time for
             * a line that had no request: the user attests availability without
             * naming a supplier inside the ERP.
             */
            $table->unsignedBigInteger('supplier_id')
                ->nullable();

            /*
             * Article snapshot (exactly one is set, mirroring the line type).
             */
            $table->unsignedBigInteger('product_variant_id')
                ->nullable();

            $table->unsignedBigInteger('non_stock_item_id')
                ->nullable();

            /*
             * Internal quantity precision, same as Inventory: DECIMAL(19,4).
             */
            $table->decimal('requested_quantity', 19, 4);

            $table->decimal('confirmed_quantity', 19, 4)
                ->nullable();

            /*
             * pending | available | partial | unavailable
             * confirmed_manually | cancelled
             */
            $table->string('status', 24)
                ->default('pending');

            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('resolved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('requested_at');

            $table->timestamp('resolved_at')
                ->nullable();

            $table->text('note')
                ->nullable();

            $table->timestamps();

            $table->unique(
                ['organization_id', 'id'],
                'qsar_org_id_unique'
            );

            $table->index(
                ['organization_id', 'quotation_id'],
                'qsar_quotation_idx'
            );

            $table->index(
                ['organization_id', 'quotation_line_id', 'status'],
                'qsar_line_status_idx'
            );

            $table->index(
                ['organization_id', 'supplier_id', 'status'],
                'qsar_supplier_status_idx'
            );

            $table->foreign(
                ['organization_id', 'store_id'],
                'qsar_store_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('stores')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'quotation_id'],
                'qsar_quotation_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('quotations')
                ->restrictOnDelete();

            /*
             * Removing a draft line deletes its requests explicitly first
             * (RemoveQuotationLineAction). RESTRICT, never cascade/SET NULL.
             */
            $table->foreign(
                ['organization_id', 'quotation_line_id'],
                'qsar_line_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('quotation_lines')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'supplier_id'],
                'qsar_supplier_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('suppliers')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'product_variant_id'],
                'qsar_variant_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('product_variants')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'non_stock_item_id'],
                'qsar_nonstock_fk'
            )
                ->references(['organization_id', 'id'])
                ->on('non_stock_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_supplier_availability_requests');

        Schema::dropIfExists('product_suppliers');
    }
};
