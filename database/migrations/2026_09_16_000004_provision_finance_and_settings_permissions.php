<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: register the new `finance.*` / `settings.view` permission
 * catalogue entries (added to config/platform.php) and sync default-role
 * assignments for every existing organisation.
 *
 * Same additive, idempotent pattern as provision_quotation_permissions,
 * provision_transfer_request_permissions and provision_procurement_permissions.
 *
 * This does NOT add the new permissions to the `admin` or `sales-employee`
 * system roles — their permission lists in config('platform.default_roles')
 * are unchanged, so provisionOrganization() is a no-op for them here
 * (syncWithoutDetaching only ever re-attaches what's already listed). Only
 * the `owner` role (permissions => '*') naturally grows to include the new
 * keys, exactly as it already does for every previous permission addition —
 * this is pre-existing, intentional behavior, not a change introduced here.
 * Finance visibility for admins is opt-in via the new "General Admin" preset
 * (config('platform.presets')), not a change to the protected admin role.
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
