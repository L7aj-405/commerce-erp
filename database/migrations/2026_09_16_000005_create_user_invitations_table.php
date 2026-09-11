<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users, Roles & Permissions V1 — admin-initiated invite-by-email.
 *
 * Organization-scoped, one-time, expiring invitation to join an org with a
 * chosen role. Mirrors the tenant-safe composite-FK style already used by
 * `organization_memberships` / `role_permission` / `store_memberships`:
 * `role_id` is tied to `(organization_id, id)` on `roles`, so an invitation
 * can never reference a role belonging to a different organization.
 *
 * Composite foreign keys here use cascadeOnDelete (not nullOnDelete): MySQL
 * requires every column of a SET NULL foreign key to be nullable, and
 * `organization_id` is not, so nullOnDelete cannot be used on the composite
 * (organization_id, role_id) constraint — same reasoning the baseline already
 * follows for `role_permission` and `store_memberships`.
 *
 * The token itself is never stored in plaintext: only `token_hash`
 * (sha256 of the random token embedded in the invite URL) is persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->unsignedBigInteger('role_id');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
            $table->foreign(['organization_id', 'role_id'])
                ->references(['organization_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
