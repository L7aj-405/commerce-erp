<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: register `supplier_catalog.manage` (added to
 * config/platform.php) and sync it onto the default roles of every existing
 * organisation — the roles that already hold `suppliers.manage`. Fresh
 * organisations pick it up via OrganizationCreator.
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
