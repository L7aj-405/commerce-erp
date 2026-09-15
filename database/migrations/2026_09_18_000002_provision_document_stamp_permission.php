<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: register the new `documents.stamp` permission catalogue
 * entry (added to config/platform.php) and sync it onto every organization's
 * `admin` system role (owner already has it via the `*` wildcard).
 *
 * Same additive, idempotent pattern as provision_finance_and_settings_permissions.
 * `general_admin` is a preset, not a default role, so — consistent with how
 * `settings.view` was handled — existing custom roles created from that
 * preset in the past are not retroactively changed; only newly created roles
 * from that preset pick up the updated permission list.
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
        // custom role assignments.
    }
};
