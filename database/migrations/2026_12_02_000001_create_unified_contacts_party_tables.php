<?php

use App\Enums\ContactSystemRole;
use App\Support\NameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Contacts, Phase 1 (backend): ONE canonical identity (Party) with many roles and optional commercial profiles.
 *
 * Strictly additive: no existing table or column is dropped or altered in meaning. customers / suppliers /
 * organization_contacts keep their ids, rows and columns; they only gain a NULLABLE party_id (+ payment_term_id) link.
 *
 *  - parties                    canonical identity (person | organization). Authoritative for shared identity fields.
 *  - contact_roles              system roles (organization_scope = 0) and organization custom roles. No privilege attached.
 *  - party_roles                a party holds many roles; deactivation keeps the row (history) and never deletes a profile.
 *  - party_relationships        person -> organization (employee / interlocutor ...), same organization only.
 *  - payment_terms              reusable organization catalogue, shared by customer and supplier profiles (no schedule engine).
 *  - party_migration_conflicts  ambiguous legacy associations found by the backfill; never auto-merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // person | organization
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('whatsapp', 64)->nullable();
            $table->string('whatsapp_username', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            // Applicable fiscal identity: all optional, length/charset validated only (never blocking format rules).
            $table->string('ice', 32)->nullable();
            $table->string('tax_identifier', 128)->nullable();
            $table->string('rc_number', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active'); // active | archived
            $table->timestamp('archived_at')->nullable();
            // 'migrated' = derived from a legacy row by the backfill; 'native' = created through the unified lifecycle.
            $table->string('origin', 16)->default('native');
            // Verbatim legacy organization_contacts.contact_type when it carried no role (preserves the meaning of 'other').
            $table->string('legacy_contact_type', 32)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'party_org_id_unique');
            $table->index(['organization_id', 'status', 'kind'], 'party_org_status_kind_idx');
            $table->index(['organization_id', 'display_name'], 'party_org_name_idx');
            $table->index(['organization_id', 'email'], 'party_org_email_idx');
        });

        Schema::create('contact_roles', function (Blueprint $table) {
            $table->id();
            // NULL organization = built-in system role. organization_scope (0 = system) makes the unique key work with NULLs.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_scope')->default(0);
            $table->string('system_key', 32)->nullable();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_scope', 'normalized_name'], 'contact_roles_scope_name_uq');
            $table->unique(['organization_scope', 'system_key'], 'contact_roles_scope_key_uq');
        });

        Schema::create('party_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('party_id');
            $table->foreignId('contact_role_id')->constrained('contact_roles')->restrictOnDelete();
            $table->string('status', 16)->default('active'); // active | inactive
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'contact_role_id'], 'party_roles_party_role_uq');
            $table->index(['organization_id', 'contact_role_id', 'status'], 'party_roles_org_role_idx');
            $table->foreign(['organization_id', 'party_id'], 'party_roles_party_fk')->references(['organization_id', 'id'])->on('parties')->cascadeOnDelete();
        });

        Schema::create('party_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('party_id'); // the person
            $table->unsignedBigInteger('related_party_id'); // the organization
            $table->string('type', 32)->default('interlocutor'); // employee | interlocutor | representative | owner
            $table->string('job_title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['party_id', 'related_party_id', 'type'], 'party_rel_unique');
            $table->index(['organization_id', 'related_party_id'], 'party_rel_related_idx');
            $table->foreign(['organization_id', 'party_id'], 'party_rel_party_fk')->references(['organization_id', 'id'])->on('parties')->cascadeOnDelete();
            $table->foreign(['organization_id', 'related_party_id'], 'party_rel_related_fk')->references(['organization_id', 'id'])->on('parties')->cascadeOnDelete();
        });

        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->string('kind', 16)->default('net_days'); // immediate | net_days
            $table->unsignedSmallInteger('days'); // 0 = comptant
            // Reserved, structured, NOT interpreted in Phase 1: e.g. {"deposit_percent":30,"installments":[{"percent":35,"days":30}]}.
            $table->json('structure')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'normalized_name'], 'payment_terms_org_name_uq');
            $table->unique(['organization_id', 'id'], 'payment_terms_org_id_unique');
        });

        Schema::create('party_migration_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 48);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('related_type', 32)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('resolution', 48); // what the backfill did (e.g. kept_separate, linked_as_relationship)
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open'); // open | reviewed
            $table->timestamps();

            $table->unique(['organization_id', 'reason', 'source_type', 'source_id', 'related_type', 'related_id'], 'party_conflict_unique');
            $table->index(['organization_id', 'status'], 'party_conflict_status_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('party_id')->nullable()->after('organization_id');
            $table->unique(['organization_id', 'party_id'], 'cust_org_party_unique');
            $table->foreign(['organization_id', 'party_id'], 'cust_party_fk')->references(['organization_id', 'id'])->on('parties')->restrictOnDelete();
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedBigInteger('party_id')->nullable()->after('organization_id');
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->unique(['organization_id', 'party_id'], 'supplier_org_party_unique');
            $table->foreign(['organization_id', 'party_id'], 'supplier_party_fk')->references(['organization_id', 'id'])->on('parties')->restrictOnDelete();
            $table->foreign(['organization_id', 'payment_term_id'], 'supplier_term_fk')->references(['organization_id', 'id'])->on('payment_terms')->restrictOnDelete();
        });
        Schema::table('customer_financial_terms', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_term_id')->nullable()->after('payment_term_days');
            $table->foreign(['organization_id', 'payment_term_id'], 'cust_terms_term_fk')->references(['organization_id', 'id'])->on('payment_terms')->restrictOnDelete();
        });
        Schema::table('organization_contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('party_id')->nullable()->after('organization_id');
            $table->index(['organization_id', 'party_id'], 'org_contacts_party_idx');
            $table->foreign(['organization_id', 'party_id'], 'org_contacts_party_fk')->references(['organization_id', 'id'])->on('parties')->restrictOnDelete();
        });

        // System roles are global rows (organization_scope = 0); organizations add their own custom roles beside them.
        $now = now();
        foreach (ContactSystemRole::cases() as $role) {
            DB::table('contact_roles')->insert([
                'organization_id' => null, 'organization_scope' => 0, 'system_key' => $role->value,
                'name' => $role->label(), 'normalized_name' => NameNormalizer::normalize($role->label()),
                'is_system' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Rolling back must never silently destroy data created through the unified lifecycle.
        if (Schema::hasTable('parties')) {
            $native = DB::table('parties')->where('origin', 'native')->count();
            $custom = Schema::hasTable('contact_roles') ? DB::table('contact_roles')->where('is_system', false)->count() : 0;
            $terms = Schema::hasTable('payment_terms') ? DB::table('payment_terms')->where('is_system', false)->count() : 0;
            if ($native + $custom + $terms > 0) {
                throw new RuntimeException("Refusing to roll back unified contacts: {$native} native parties, {$custom} custom roles and {$terms} custom payment terms would be lost. Export or merge them first.");
            }
        }

        Schema::table('organization_contacts', function (Blueprint $table) {
            $table->dropForeign('org_contacts_party_fk');
            $table->dropIndex('org_contacts_party_idx');
            $table->dropColumn('party_id');
        });
        Schema::table('customer_financial_terms', function (Blueprint $table) {
            $table->dropForeign('cust_terms_term_fk');
            $table->dropColumn('payment_term_id');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropForeign('supplier_term_fk');
            $table->dropForeign('supplier_party_fk');
            $table->dropUnique('supplier_org_party_unique');
            $table->dropColumn(['party_id', 'payment_term_id']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign('cust_party_fk');
            $table->dropUnique('cust_org_party_unique');
            $table->dropColumn('party_id');
        });
        Schema::dropIfExists('party_migration_conflicts');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('party_relationships');
        Schema::dropIfExists('party_roles');
        Schema::dropIfExists('contact_roles');
        Schema::dropIfExists('parties');
    }
};
