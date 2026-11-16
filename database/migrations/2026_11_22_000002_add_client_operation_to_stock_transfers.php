<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable idempotency for stock transfers (Production and Test ledgers).
 *
 * client_operation_id is the browser-generated UUID of ONE submit; the unique key makes the
 * database the arbiter of "executed once". client_operation_hash fingerprints the payload so
 * the same id reused with different data is refused instead of silently replayed.
 * Additive, nullable: existing and API-created transfers are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('client_operation_id', 64)->nullable()->after('transfer_number');
            $table->string('client_operation_hash', 64)->nullable()->after('client_operation_id');
            $table->unique(['organization_id', 'client_operation_id'], 'stock_transfer_operation_unique');
        });
        Schema::table('sandbox_stock_transfers', function (Blueprint $table) {
            $table->string('client_operation_id', 64)->nullable()->after('transfer_number');
            $table->string('client_operation_hash', 64)->nullable()->after('client_operation_id');
            $table->unique(['sandbox_id', 'organization_id', 'client_operation_id'], 'sbx_stock_transfer_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sandbox_stock_transfers', function (Blueprint $table) {
            $table->dropUnique('sbx_stock_transfer_operation_unique');
            $table->dropColumn(['client_operation_id', 'client_operation_hash']);
        });
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropUnique('stock_transfer_operation_unique');
            $table->dropColumn(['client_operation_id', 'client_operation_hash']);
        });
    }
};
