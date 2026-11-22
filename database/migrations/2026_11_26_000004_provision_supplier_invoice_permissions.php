<?php

use App\Services\PermissionProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 2 Sprint 1: supplier invoice permissions.
 *
 * Equivalence provisioning, in every organization, for system, preset-derived and custom roles alike:
 * - `supplier_invoices.view` + `.manage` go to holders of `procurement.manage` OR `finance.monthly_situation.manage`;
 * - `supplier_invoices.approve` + `.void` go to holders of `finance.monthly_situation.manage` ONLY (approval creates
 *   payable obligations, so it follows the Finance right, not the purchasing right).
 * Roles holding none of those are never touched, default roles are deliberately NOT re-synced from config (that would
 * hand the new rights to an organization that removed the source permission from a default role), nothing is ever
 * revoked, and re-running is a no-op. Fresh organizations get the same defaults from config/platform.php.
 */
return new class extends Migration
{
    private const KEYS = [
        'supplier_invoices.view',
        'supplier_invoices.manage',
        'supplier_invoices.approve',
        'supplier_invoices.void',
    ];

    public function up(): void
    {
        $provisioner = app(PermissionProvisioner::class);
        $provisioner->provisionKeysOrFail(['procurement.manage', 'finance.monthly_situation.manage', ...self::KEYS]);

        foreach (['procurement.manage', 'finance.monthly_situation.manage'] as $holder) {
            $provisioner->grantToHoldersOf($holder, 'supplier_invoices.view');
            $provisioner->grantToHoldersOf($holder, 'supplier_invoices.manage');
        }
        $provisioner->grantToHoldersOf('finance.monthly_situation.manage', 'supplier_invoices.approve');
        $provisioner->grantToHoldersOf('finance.monthly_situation.manage', 'supplier_invoices.void');
    }

    public function down(): void
    {
        // Granted rights and customized role assignments are intentionally retained.
    }
};
