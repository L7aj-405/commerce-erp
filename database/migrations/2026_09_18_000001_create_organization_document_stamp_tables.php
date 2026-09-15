<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization company-stamp ("cachet de l'entreprise") domain.
 *
 * Two tables, deliberately kept separate:
 *
 * `organization_document_stamps` — the organization's stamp CONFIGURATION.
 * Rows are append-only / versioned: uploading a new image or tweaking
 * position/size never UPDATEs an existing row — it inserts a new one and
 * flips `active` off on the previous one. This is what makes a historical
 * apposition immutable (see below) even though "the org's stamp" is
 * conceptually mutable — a past apposition's frozen columns, and its
 * `organization_document_stamp_id` reference, point at a row that is never
 * mutated again once superseded. One row has `active = true` per
 * organization at a time (enforced at the application layer, the same way
 * the invoice/quotation "one current version" invariant is — see
 * StartInvoiceCorrectionAction / StartQuotationRevisionAction for the
 * precedent this follows).
 *
 * `document_stamp_appositions` — the record that a specific document was
 * actually stamped. Polymorphic (`stampable_type`/`stampable_id`) so the same
 * table serves Invoice, Quotation, and later Delivery Note / Bon de sortie /
 * other official documents without new tables per document type. Per
 * organization/document, at most one row (unique index) — V1 apposition is a
 * one-way action. Columns duplicate the position/size/image path that were
 * active on `organization_document_stamp_id` at the moment of apposition, in
 * addition to keeping that FK — belt and suspenders immutability: even if a
 * future bug ever mutated a stamp-version row in place, the frozen columns
 * here still reproduce exactly what was applied, matching how
 * `invoices.seller_snapshot` / `quotations.seller_snapshot` freeze the
 * Document Profile rather than re-reading it live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_document_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('image_path'); // private disk ("local") — never a public URL
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 64);
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->string('position_anchor', 16)->default('bottom_left'); // bottom_left|bottom_right|top_left|top_right
            $table->decimal('offset_x_mm', 6, 2)->default(0);
            $table->decimal('offset_y_mm', 6, 2)->default(5);
            $table->decimal('display_width_mm', 6, 2)->default(35);
            $table->string('source', 16)->default('uploaded'); // uploaded|digitized (future digitization pipeline)
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'active', 'id'], 'org_doc_stamps_active_idx');
            $table->unique(['organization_id', 'id'], 'org_doc_stamps_org_id_unique');
        });

        Schema::create('document_stamp_appositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('stampable_type');
            $table->unsignedBigInteger('stampable_id');
            $table->unsignedBigInteger('organization_document_stamp_id');
            // Frozen copy of the stamp-version fields at the moment of apposition.
            $table->string('image_path');
            $table->string('mime_type', 64);
            $table->string('position_anchor', 16);
            $table->decimal('offset_x_mm', 6, 2);
            $table->decimal('offset_y_mm', 6, 2);
            $table->decimal('display_width_mm', 6, 2);
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at');
            $table->timestamps();

            $table->unique(['stampable_type', 'stampable_id'], 'doc_stamp_appositions_stampable_unique');
            $table->index(['organization_id', 'stampable_type', 'stampable_id'], 'doc_stamp_appositions_org_idx');
            $table->foreign(['organization_id', 'organization_document_stamp_id'], 'doc_stamp_appositions_stamp_fk')
                ->references(['organization_id', 'id'])->on('organization_document_stamps')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_stamp_appositions');
        Schema::dropIfExists('organization_document_stamps');
    }
};
