<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name');
            $table->string('company_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('whatsapp', 64)->nullable();
            $table->string('contact_type', 32)->default('other');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'contact_type', 'active'], 'org_contacts_type_active_idx');
            $table->index(['organization_id', 'email'], 'org_contacts_email_idx');
        });

        Schema::create('document_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 64);
            $table->unsignedBigInteger('document_id');
            $table->json('to_recipients');
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            $table->string('subject');
            $table->string('status', 32);
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'document_type', 'document_id'], 'doc_email_org_doc_idx');
            $table->index(['organization_id', 'status', 'created_at'], 'doc_email_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_email_deliveries');
        Schema::dropIfExists('organization_contacts');
    }
};
