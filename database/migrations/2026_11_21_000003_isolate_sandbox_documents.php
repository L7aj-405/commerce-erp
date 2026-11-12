<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Test documents. Root documents carry explicit sandbox ownership
 * (NULL = Production). Test references come from their own counter table and
 * never touch invoice_sequences / delivery_note_sequences / credit_note_sequences.
 */
return new class extends Migration
{
    private const TABLES = ['invoice_families', 'invoices', 'delivery_notes', 'credit_notes'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('sandbox_id')->nullable()->constrained('sandboxes')->restrictOnDelete();
                $table->index(['organization_id', 'sandbox_id'], $name.'_environment_idx');
            });
        }

        // Organization-wide (not per sandbox) so a recreated sandbox can never
        // reissue a TEST- reference that collides with the per-organization
        // unique indexes on document numbers.
        Schema::create('sandbox_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'document_type'], 'sandbox_doc_sequence_unique');
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::table($name)->whereNotNull('sandbox_id')->exists()) {
                throw new RuntimeException('Cannot remove environment isolation while Test documents exist.');
            }
        }

        Schema::dropIfExists('sandbox_document_sequences');
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_environment_idx');
                $table->dropConstrainedForeignId('sandbox_id');
            });
        }
    }
};
