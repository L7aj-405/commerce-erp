<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase C6: adds `commissions.export` and `commissions.own.view` to the
 * permission catalog and grants them to system default roles only (Owner via
 * '*', Admin for export). Custom roles — including roles created earlier from
 * the Finance preset — are never changed; `commissions.own.view` is granted
 * to nobody but Owner until an administrator explicitly assigns it.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionProvisioner::class)->provisionAllOrganizations();
    }

    public function down(): void
    {
        // Preserve grants on rollback; custom roles must never be silently changed.
    }
};
