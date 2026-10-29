<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: register the F1.1 fulfillment permissions (added to
 * config/platform.php) and sync them onto the default roles that already hold
 * `sales_orders.fulfill`. Fresh organisations pick them up via OrganizationCreator.
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
