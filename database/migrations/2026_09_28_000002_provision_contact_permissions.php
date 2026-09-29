<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: register the `contacts.*` permission catalogue entries
 * and sync them onto every organization's default system roles according to
 * config/platform.php.
 *
 * This is intentionally additive and idempotent: existing custom roles are not
 * changed, while Owner/Admin (and any other configured default system role)
 * receive the permissions listed in the default-role definitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionProvisioner::class)->provisionAllOrganizations();
    }

    public function down(): void
    {
        // Permission records are retained so rollback cannot silently revoke
        // existing role assignments.
    }
};
