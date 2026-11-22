<?php

namespace App\Actions\SupplierInvoices\Concerns;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Sandbox\SandboxContext;
use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use App\Services\SupplierInvoices\SupplierInvoiceStoreAccess;

/**
 * Common authorization of the supplier invoice lifecycle actions: Test Mode and the kill switch hide the
 * feature (404), then organization + explicit permission (403), then explicit store membership (403, no
 * Owner/Admin bypass). Always run BEFORE any idempotent shortcut.
 */
trait AuthorizesSupplierInvoiceAction
{
    protected function assertInvoiceAccess(User $actor, Organization $organization, string $permission): void
    {
        abort_if(app(SandboxContext::class)->isSandbox(), 404);
        abort_unless(app(SupplierInvoiceSettings::class)->enabled(), 404);
        abort_unless(
            $organization->status === 'active'
                && $actor->active_organization_id === $organization->getKey()
                && $actor->hasPermission($organization, $permission),
            403,
        );
    }

    /** @param 'canCreate'|'canEdit'|'canApprove'|'canView'|'canVoid' $ability */
    protected function assertStoreAccess(User $actor, Store $store, string $ability): void
    {
        abort_unless(app(SupplierInvoiceStoreAccess::class)->{$ability}($actor, $store), 403);
    }
}
