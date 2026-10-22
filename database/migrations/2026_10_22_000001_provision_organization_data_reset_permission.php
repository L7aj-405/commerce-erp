<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionProvisioner::class)->provisionAllOrganizations();
    }

    public function down(): void
    {
        // Retain the permission and any custom assignments. Rollback must not
        // silently alter access-control intent in an existing organization.
    }
};
