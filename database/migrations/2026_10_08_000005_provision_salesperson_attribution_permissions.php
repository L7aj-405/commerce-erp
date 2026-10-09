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
        // Retain grants to avoid silently revoking production access.
    }
};
