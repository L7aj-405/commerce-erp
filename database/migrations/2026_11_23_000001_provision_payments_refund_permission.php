<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 1 Step 6 (S-03): `payments.refund` is separated from `payments.reverse`.
 *
 * Equivalence provisioning: every role that holds `payments.reverse` today (system, preset-derived or custom) also
 * receives `payments.refund`, in every organization, so nobody gains or loses an effective right. Roles WITHOUT
 * `payments.reverse` are never touched, and default roles are deliberately NOT re-synced from config (that would
 * hand the new permission to an organization that removed `payments.reverse` from its Admin role). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(PermissionProvisioner::class);
        $provisioner->provisionKeysOrFail(['payments.reverse', 'payments.refund']);
        $provisioner->grantToHoldersOf('payments.reverse', 'payments.refund');
    }

    public function down(): void
    {
        // Granted rights and customized role assignments are intentionally retained.
    }
};
