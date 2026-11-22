<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Sprint 1 (PR1): optional fiscal / payment identity of a supplier.
 *
 * Every column is nullable: existing suppliers stay valid and nothing is
 * backfilled. ICE / IF / RC are free text validated for length and charset
 * only (format hints are advisory warnings in the application, never blocking).
 * bank_iban is sensitive: never listed, never in MCP reads, masked in audit.
 */
return new class extends Migration
{
    private const COLUMNS = ['ice', 'tax_identifier', 'rc_number', 'default_payment_term_days', 'default_currency_code', 'bank_iban'];

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('ice', 32)->nullable();
            $table->string('tax_identifier', 32)->nullable();
            $table->string('rc_number', 32)->nullable();
            $table->unsignedSmallInteger('default_payment_term_days')->nullable();
            $table->char('default_currency_code', 3)->nullable();
            $table->string('bank_iban', 40)->nullable();
        });
    }

    public function down(): void
    {
        // Dropping the columns would silently destroy fiscal identity data:
        // refuse loudly when any supplier carries one.
        $query = DB::table('suppliers');
        $query->where(function ($query) {
            foreach (self::COLUMNS as $column) {
                $query->orWhereNotNull($column);
            }
        });

        if ($query->exists()) {
            throw new RuntimeException('Refusing to drop supplier fiscal identity columns: at least one supplier has a value in ice, tax_identifier, rc_number, default_payment_term_days, default_currency_code or bank_iban. Nothing was changed.');
        }

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
