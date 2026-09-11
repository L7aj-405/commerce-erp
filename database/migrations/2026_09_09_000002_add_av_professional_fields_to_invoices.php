<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-invoice variable fields required by the "AV Professional" document.
 *
 * - `representative_name`   — the seller's representative shown in the meta
 *                             table. Defaults to the employee who finalised the
 *                             originating POS sale; correctable while draft.
 * - `payment_method_summary`— a concise French rendering of the payment methods
 *                             actually used on the source Order (e.g.
 *                             "ESPÈCES / TPE"). It is derived from Order
 *                             payments, never a new Invoice payment.
 *
 * Depends on `invoices` created in 2026_08_23_000006_create_document_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('representative_name')->nullable()->after('billing_address');
            $table->string('payment_method_summary')->nullable()->after('representative_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['representative_name', 'payment_method_summary']);
        });
    }
};
