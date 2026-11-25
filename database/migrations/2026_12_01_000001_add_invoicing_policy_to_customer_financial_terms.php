<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPT-FC-004. Nullable on purpose: NULL = "use the default of the customer type"
 * (Company = invoice before payment allowed, Individual = after full payment). No backfill, no data change,
 * no invoice or transaction is touched. Existing Company customers therefore become eligible for
 * invoice-before-payment as soon as this ships; an authorized user can pin "after_full_payment" per customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_financial_terms', function (Blueprint $table) {
            $table->string('invoicing_policy', 24)->nullable()->after('financial_status');
        });
    }

    public function down(): void
    {
        Schema::table('customer_financial_terms', function (Blueprint $table) {
            $table->dropColumn('invoicing_policy');
        });
    }
};
