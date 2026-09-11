<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annual invoice numbering.
 *
 * Official invoice numbers are `N/YYYY` and restart at 1 every calendar year
 * (Moroccan convention: 1/2026 … 56/2026, then 1/2027). The sequence is
 * therefore keyed per (organization, year) instead of per organization.
 *
 * The table only ever holds the *next* counter; issued invoices persist their
 * own allocated number, so nothing of value is lost by dropping and recreating
 * it. Depends on `invoice_sequences` created in
 * 2026_08_23_000006_create_document_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('invoice_sequences');

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->primary(['organization_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
    }
};
