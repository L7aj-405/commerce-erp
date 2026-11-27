<?php

use App\Services\Contacts\PartyBackfillService;
use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Data migration for unified contacts (Phase 1): registers the new permissions and backfills one Party per legacy
 * customer / supplier / contact. Additive and idempotent (see PartyBackfillService). The conflict report is stored in
 * party_migration_conflicts; the counters are logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(PermissionProvisioner::class);
        $provisioner->provisionKeysOrFail(['contacts.roles.manage', 'contacts.payment_terms.manage']);
        $provisioner->provisionAllOrganizations();

        $report = app(PartyBackfillService::class)->run();
        Log::info('unified-contacts backfill', $report);
    }

    public function down(): void
    {
        // The parties created by the backfill are derivable from the legacy rows and are removed by the schema
        // migration's rollback; permissions are retained so existing role assignments are never silently revoked.
    }
};
