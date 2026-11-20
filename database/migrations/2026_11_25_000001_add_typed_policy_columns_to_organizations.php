<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Step 11 (D-F2): typed organization policy columns, WITHOUT EFFECT.
 *
 * - invoice_issuance / credit_limit_enforcement: closed value sets whose defaults reproduce today's behavior. No
 *   business Action reads them yet.
 * - sales_order_payment_term_days / invoice_payment_term_days: NULL = inherit the environment value
 *   (SALES_ORDER_PAYMENT_TERM_DAYS = 7, INVOICE_PAYMENT_TERM_DAYS = 30), so every existing organization keeps
 *   exactly the due dates it gets today. An override only affects documents stamped afterwards.
 *
 * Additive only; no existing row is modified beyond the column defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('invoice_issuance', 32)->default('after_full_payment');
            $table->string('credit_limit_enforcement', 32)->default('warn');
            $table->unsignedSmallInteger('sales_order_payment_term_days')->nullable();
            $table->unsignedSmallInteger('invoice_payment_term_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['invoice_issuance', 'credit_limit_enforcement', 'sales_order_payment_term_days', 'invoice_payment_term_days']);
        });
    }
};
