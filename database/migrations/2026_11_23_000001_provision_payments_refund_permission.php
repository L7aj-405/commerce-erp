<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        app(PermissionProvisioner::class)->provisionCatalog();

        $reverseId = DB::table('permissions')->where('key', 'payments.reverse')->value('id');
        $refundId = DB::table('permissions')->where('key', 'payments.refund')->value('id');
        if ($reverseId === null || $refundId === null) {
            return;
        }

        DB::table('role_permission')->where('permission_id', $reverseId)->orderBy('role_id')->each(function ($grant) use ($refundId) {
            $exists = DB::table('role_permission')->where('role_id', $grant->role_id)->where('permission_id', $refundId)->exists();
            if (! $exists) {
                DB::table('role_permission')->insert([
                    'organization_id' => $grant->organization_id,
                    'role_id' => $grant->role_id,
                    'permission_id' => $refundId,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Granted rights and customized role assignments are intentionally retained.
    }
};
