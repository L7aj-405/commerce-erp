<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: register the `quotations.*` permission catalogue entries
 * (added to config/platform.php) and sync them onto the default roles of every
 * existing organisation. Fresh organisations pick them up via OrganizationCreator.
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
