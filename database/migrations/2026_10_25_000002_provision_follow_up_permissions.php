<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: register the `follow_ups.*` permission catalogue entries and
 * sync them onto every organization's default system roles according to
 * config/platform.php (Owner: all; Admin: all incl. manage_all; Sales
 * Employee: own work only). Additive and idempotent; custom roles unchanged.
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
