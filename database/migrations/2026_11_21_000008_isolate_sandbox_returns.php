<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Test returns, exchanges, return refunds and return credit notes.
 *
 * Returns and exchanges are roots that Production AND Test rows already point
 * at through composite (organization_id, id) foreign keys: credit notes
 * (single table, sandbox_id since Phase 3), the Test refunds and Test
 * commission entries of Phase 5. They therefore follow the same explicit
 * ownership pattern as sales orders / invoices / credit notes: a nullable
 * sandbox_id (NULL = Production), a non-removable global scope and a model
 * guard on every link. Production readers that query the tables directly
 * filter on sandbox_id IS NULL.
 *
 * The exchange <-> payment pivot is the one place that must point at the
 * routed Test payment ledger, so Test mode gets its own pivot table with a
 * (sandbox_id, payment_id) foreign key.
 *
 * Test references (TEST-RET-…, TEST-ECH-…, TEST-AV-…) come from
 * sandbox_document_sequences and never touch Production sequences.
 */
return new class extends Migration
{
    private const TABLES = ['customer_returns', 'customer_return_lines', 'customer_exchanges'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('sandbox_id')->nullable();
                $table->foreign('sandbox_id', 'cr_'.substr(md5($name), 0, 8).'_sbx_fk')->references('id')->on('sandboxes')->restrictOnDelete();
                $table->index(['organization_id', 'sandbox_id'], 'cr_'.substr(md5($name), 0, 8).'_env_idx');
            });
        }

        Schema::create('sandbox_customer_exchange_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sandbox_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('customer_exchange_id');
            $table->unsignedBigInteger('payment_id');
            $table->timestamps();

            $table->unique(['sandbox_id', 'customer_exchange_id', 'payment_id'], 'sbx_cxp_unique');
            $table->foreign('sandbox_id', 'sbx_cxp_sandbox_fk')->references('id')->on('sandboxes')->restrictOnDelete();
            $table->foreign(['organization_id', 'customer_exchange_id'], 'sbx_cxp_exchange_fk')->references(['organization_id', 'id'])->on('customer_exchanges')->restrictOnDelete();
            $table->foreign(['sandbox_id', 'payment_id'], 'sbx_cxp_payment_fk')->references(['sandbox_id', 'id'])->on('sandbox_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Dropping isolation would silently merge Test after-sales records into Production.
        foreach (self::TABLES as $name) {
            if (DB::table($name)->whereNotNull('sandbox_id')->exists()) {
                throw new RuntimeException('Cannot remove returns isolation while Test returns or exchanges exist.');
            }
        }
        if (DB::table('sandbox_customer_exchange_payments')->exists()) {
            throw new RuntimeException('Cannot remove returns isolation while Test returns or exchanges exist.');
        }

        Schema::dropIfExists('sandbox_customer_exchange_payments');
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign('cr_'.substr(md5($name), 0, 8).'_sbx_fk');
                $table->dropIndex('cr_'.substr(md5($name), 0, 8).'_env_idx');
                $table->dropColumn('sandbox_id');
            });
        }
    }
};
