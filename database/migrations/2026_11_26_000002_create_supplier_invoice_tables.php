<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Sprint 1 (PR2): supplier invoice (facture fournisseur) schema.
 *
 * A supplier invoice is a commercial document the organization RECEIVES. On
 * approval (later PR) it generates one finance_obligation per installment; it
 * never posts stock, payments or account movements itself. This migration only
 * creates the tables:
 *
 *   supplier_invoice_sequences      per-organization/year numbering (FAF-YYYY-000001)
 *   supplier_invoices               header, totals (decimal 19,4 strings), status lifecycle
 *   supplier_invoice_lines          priced lines with a frozen tax snapshot
 *   supplier_invoice_installments   payment schedule (the obligation link lives on
 *                                   finance_obligations, added by a later migration)
 *   supplier_invoice_attachments    metadata of stored scans (files are not in backups)
 *
 * Every table carries organization_id and every cross-table reference is a
 * composite (organization_id, id) RESTRICT foreign key, so a row can never
 * point at another organization's data. supplier_invoices.dedupe_key is
 * UNIQUE per (organization, supplier) and NULL for void invoices: NULLs never
 * collide, which frees the supplier invoice number once an invoice is voided.
 *
 * CHECK constraints follow 2026_11_24_000001_add_financial_check_constraints:
 * native on MySQL 8.0.16+/8.4, BEFORE INSERT/UPDATE triggers on SQLite.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, name: string, column: string, op: string}> */
    private const RULES = [
        ['table' => 'supplier_invoices', 'name' => 'chk_sinv_subtotal_ht_nonneg', 'column' => 'subtotal_ht', 'op' => '>='],
        ['table' => 'supplier_invoices', 'name' => 'chk_sinv_total_tva_nonneg', 'column' => 'total_tva', 'op' => '>='],
        ['table' => 'supplier_invoices', 'name' => 'chk_sinv_total_ttc_nonneg', 'column' => 'total_ttc', 'op' => '>='],
        ['table' => 'supplier_invoice_lines', 'name' => 'chk_sinv_line_qty_positive', 'column' => 'quantity', 'op' => '>'],
        ['table' => 'supplier_invoice_lines', 'name' => 'chk_sinv_line_price_nonneg', 'column' => 'unit_price_ht', 'op' => '>='],
        ['table' => 'supplier_invoice_installments', 'name' => 'chk_sinv_inst_amount_positive', 'column' => 'amount', 'op' => '>'],
    ];

    public function up(): void
    {
        Schema::create('supplier_invoice_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->primary(['organization_id', 'year'], 'sinv_seq_pk');
            $table->foreign('organization_id', 'sinv_seq_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('store_id');
            $table->string('internal_number', 32);
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('invoice_number', 64);
            $table->string('dedupe_key', 100)->nullable();
            $table->date('invoice_date');
            $table->char('currency_code', 3);
            $table->decimal('subtotal_ht', 19, 4)->default(0);
            $table->decimal('total_tva', 19, 4)->default(0);
            $table->decimal('rounding_adjustment', 19, 4)->default(0);
            $table->decimal('total_ttc', 19, 4)->default(0);
            $table->decimal('control_total_ttc', 19, 4)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('match_status_at_approval', 24)->nullable();
            $table->text('match_note')->nullable();
            $table->string('supplier_name_snapshot')->nullable();
            $table->string('supplier_ice_snapshot', 32)->nullable();
            $table->string('supplier_tax_identifier_snapshot', 32)->nullable();
            $table->string('supplier_rc_snapshot', 32)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('client_operation_id')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('last_saved_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason_code', 32)->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'sinv_org_id_uq');
            $table->unique(['organization_id', 'internal_number'], 'sinv_org_number_uq');
            $table->unique(['organization_id', 'client_operation_id'], 'sinv_org_client_op_uq');
            $table->unique(['organization_id', 'supplier_id', 'dedupe_key'], 'sinv_org_supplier_dedupe_uq');
            $table->index(['organization_id', 'store_id', 'status'], 'sinv_org_store_status_idx');
            $table->index(['organization_id', 'supplier_id', 'invoice_date'], 'sinv_org_supplier_date_idx');
            $table->index(['organization_id', 'status', 'invoice_date'], 'sinv_org_status_date_idx');

            $table->foreign('organization_id', 'sinv_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'store_id'], 'sinv_store_fk')->references(['organization_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['organization_id', 'supplier_id'], 'sinv_supplier_fk')->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['organization_id', 'purchase_order_id'], 'sinv_po_fk')->references(['organization_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sinv_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('last_saved_by_user_id', 'sinv_saved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id', 'sinv_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('voided_by_user_id', 'sinv_voided_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->unsignedInteger('position');
            $table->string('cost_nature', 16)->default('inventory');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price_ht', 19, 4);
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->decimal('tax_rate_percent', 7, 4);
            $table->string('tax_name_snapshot')->nullable();
            $table->decimal('line_ht', 19, 4);
            $table->decimal('line_tva', 19, 4);
            $table->decimal('line_ttc', 19, 4);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'sinv_line_org_id_uq');
            $table->index(['organization_id', 'supplier_invoice_id', 'position'], 'sinv_line_invoice_pos_idx');
            $table->index(['organization_id', 'purchase_order_line_id'], 'sinv_line_po_line_idx');
            $table->index(['organization_id', 'product_variant_id'], 'sinv_line_variant_idx');

            $table->foreign('organization_id', 'sinv_line_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'supplier_invoice_id'], 'sinv_line_invoice_fk')->references(['organization_id', 'id'])->on('supplier_invoices')->restrictOnDelete();
            $table->foreign(['organization_id', 'product_variant_id'], 'sinv_line_variant_fk')->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['organization_id', 'purchase_order_line_id'], 'sinv_line_po_line_fk')->references(['organization_id', 'id'])->on('purchase_order_lines')->restrictOnDelete();
            $table->foreign(['organization_id', 'tax_rate_id'], 'sinv_line_tax_fk')->references(['organization_id', 'id'])->on('tax_rates')->restrictOnDelete();
        });

        Schema::create('supplier_invoice_installments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->unsignedInteger('position');
            $table->date('due_date');
            $table->decimal('amount', 19, 4);
            $table->string('planned_payment_method', 32)->nullable();
            $table->unsignedBigInteger('preferred_financial_account_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'sinv_inst_org_id_uq');
            $table->index(['organization_id', 'supplier_invoice_id', 'position'], 'sinv_inst_invoice_pos_idx');
            $table->index(['organization_id', 'due_date'], 'sinv_inst_org_due_idx');

            $table->foreign('organization_id', 'sinv_inst_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'supplier_invoice_id'], 'sinv_inst_invoice_fk')->references(['organization_id', 'id'])->on('supplier_invoices')->restrictOnDelete();
            $table->foreign(['organization_id', 'preferred_financial_account_id'], 'sinv_inst_account_fk')->references(['organization_id', 'id'])->on('financial_accounts')->restrictOnDelete();
        });

        Schema::create('supplier_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->string('disk', 32);
            $table->string('storage_key', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'sinv_att_org_id_uq');
            $table->unique(['organization_id', 'supplier_invoice_id', 'sha256'], 'sinv_att_invoice_sha_uq');
            $table->index(['organization_id', 'sha256'], 'sinv_att_org_sha_idx');

            $table->foreign('organization_id', 'sinv_att_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'supplier_invoice_id'], 'sinv_att_invoice_fk')->references(['organization_id', 'id'])->on('supplier_invoices')->restrictOnDelete();
            $table->foreign('uploaded_by_user_id', 'sinv_att_uploaded_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        foreach (self::RULES as $rule) {
            $this->addCheck($rule);
        }
    }

    public function down(): void
    {
        // Financial documents are never discarded by a rollback: refuse loudly
        // rather than silently destroying supplier invoices.
        if (Schema::hasTable('supplier_invoices') && DB::table('supplier_invoices')->exists()) {
            throw new RuntimeException('Refusing to drop supplier invoice tables: supplier_invoices contains rows. Export or void them through the application first; nothing was changed.');
        }

        foreach (array_reverse(self::RULES) as $rule) {
            $this->dropCheck($rule);
        }

        Schema::dropIfExists('supplier_invoice_attachments');
        Schema::dropIfExists('supplier_invoice_installments');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('supplier_invoice_sequences');
    }

    /** @param array{table: string, name: string, column: string, op: string} $rule */
    private function addCheck(array $rule): void
    {
        ['table' => $table, 'name' => $name, 'column' => $column, 'op' => $op] = $rule;

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                DB::statement(
                    "CREATE TRIGGER {$name}_{$suffix} BEFORE {$event} ON {$table} "
                    ."WHEN NEW.{$column} IS NOT NULL AND NOT (NEW.{$column} {$op} 0) "
                    ."BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: {$name}'); END",
                );
            }

            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} {$op} 0)");
    }

    /** @param array{table: string, name: string, column: string, op: string} $rule */
    private function dropCheck(array $rule): void
    {
        ['table' => $table, 'name' => $name] = $rule;

        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("DROP TRIGGER IF EXISTS {$name}_insert");
            DB::statement("DROP TRIGGER IF EXISTS {$name}_update");

            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
    }
};
