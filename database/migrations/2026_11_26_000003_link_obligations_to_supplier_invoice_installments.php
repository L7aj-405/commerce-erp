<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Sprint 1 (PR7): single-direction link from a finance obligation to the supplier invoice installment it
 * was generated from. NULL for every legacy / manual / recurring / installment-plan obligation (no backfill).
 *
 * - composite tenant FK (organization_id, installment) RESTRICT;
 * - UNIQUE (organization_id, supplier_invoice_installment_id): one obligation per installment, ever;
 * - exclusivity CHECK: a linked obligation can never also belong to a supplier installment plan or a recurring
 *   template (native CHECK on MySQL, BEFORE INSERT/UPDATE triggers on SQLite, as in 2026_11_24_000001).
 */
return new class extends Migration
{
    private const CHECK = 'chk_fin_obl_inv_exclusive';

    public function up(): void
    {
        Schema::table('finance_obligations', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_invoice_installment_id')->nullable()->after('sales_order_procurement_id');
            $table->unique(['organization_id', 'supplier_invoice_installment_id'], 'fin_obl_sinv_inst_uq');
            $table->foreign(['organization_id', 'supplier_invoice_installment_id'], 'fin_obl_sinv_inst_fk')
                ->references(['organization_id', 'id'])->on('supplier_invoice_installments')->restrictOnDelete();
        });

        $condition = 'supplier_invoice_installment_id IS NOT NULL AND (supplier_installment_plan_id IS NOT NULL OR recurring_template_id IS NOT NULL)';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                $new = str_replace(['supplier_invoice_installment_id', 'supplier_installment_plan_id', 'recurring_template_id'], ['NEW.supplier_invoice_installment_id', 'NEW.supplier_installment_plan_id', 'NEW.recurring_template_id'], $condition);
                DB::statement('CREATE TRIGGER '.self::CHECK."_{$suffix} BEFORE {$event} ON finance_obligations WHEN {$new} BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: ".self::CHECK."'); END");
            }

            return;
        }

        DB::statement('ALTER TABLE finance_obligations ADD CONSTRAINT '.self::CHECK." CHECK (NOT ({$condition}))");
    }

    public function down(): void
    {
        if (DB::table('finance_obligations')->whereNotNull('supplier_invoice_installment_id')->exists()) {
            throw new RuntimeException('Refusing to drop finance_obligations.supplier_invoice_installment_id: obligations generated from supplier invoices exist and the link would be lost.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::CHECK.'_insert');
            DB::statement('DROP TRIGGER IF EXISTS '.self::CHECK.'_update');
        } else {
            DB::statement('ALTER TABLE finance_obligations DROP CHECK '.self::CHECK);
        }

        Schema::table('finance_obligations', function (Blueprint $table) {
            $table->dropForeign('fin_obl_sinv_inst_fk');
            $table->dropUnique('fin_obl_sinv_inst_uq');
            $table->dropColumn('supplier_invoice_installment_id');
        });
    }
};
