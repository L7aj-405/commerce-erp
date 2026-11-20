<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 1 Step 11: `settings.billing.manage` and `customers.manage_credit` are new named permissions guarding the
 * typed policy changes. Equivalence provisioning: roles that can already change organization settings
 * (`settings.update`) receive both, so the people who could administer the organization before still can and nobody
 * else gains anything. The policies have no business effect yet. Default roles are NOT re-synced from config.
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(PermissionProvisioner::class);
        $provisioner->provisionKeysOrFail(['settings.update', 'settings.billing.manage', 'customers.manage_credit']);
        $provisioner->grantToHoldersOf('settings.update', 'settings.billing.manage');
        $provisioner->grantToHoldersOf('settings.update', 'customers.manage_credit');
    }

    public function down(): void
    {
        // Granted rights and customized role assignments are intentionally retained.
    }
};
