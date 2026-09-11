<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration (kept separate from schema): seed the permission catalogue from
 * config/platform.php and sync default-role assignments for any organisation that
 * already exists.
 *
 * Replaces the nine historical per-domain `provision_*` migrations and the inline
 * permission blocks in the product-import and stock-transfer migrations. Runs
 * last, after every schema table exists.
 *
 * On a fresh database this only populates `permissions`; role assignments happen
 * per organisation at creation time via OrganizationCreator.
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
