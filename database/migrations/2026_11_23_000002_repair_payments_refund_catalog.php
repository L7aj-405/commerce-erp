<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 6 UAT remediation. The Role Editor catalogue is the `permissions` table, filled only by provisioning
 * migrations. 2026_11_23_000001 originally returned silently when the `payments.refund` row could not be created,
 * so an environment could record it as applied (or never run it: RUN_MIGRATIONS is off by default) and still show
 * only `payments.reverse`.
 *
 * Heals exactly that state and nothing else: when the `payments.refund` row is MISSING it is created and given to
 * the holders of `payments.reverse` (equivalence, no escalation). When the row already exists, the earlier
 * migration did its job and organizations may have customised roles since, so NOTHING is granted again.
 * Fails loudly (stale config cache) instead of recording a no-op. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(PermissionProvisioner::class);
        $rowExisted = DB::table('permissions')->where('key', 'payments.refund')->exists();

        $provisioner->provisionKeysOrFail(['payments.reverse', 'payments.refund']);

        if (! $rowExisted) {
            $provisioner->grantToHoldersOf('payments.reverse', 'payments.refund');
        }
    }

    public function down(): void
    {
        // Granted rights and customized role assignments are intentionally retained.
    }
};
