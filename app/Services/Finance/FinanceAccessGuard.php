<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

/**
 * Finance's own authorization path — deliberately independent of
 * ActiveTenantContext and of InvoicePolicy/PaymentPolicy.
 *
 * Those existing policies hard-require the user's currently active STORE to
 * match the record being viewed (see InvoicePolicy::scope() /
 * PaymentPolicy::scope()), because ordinary Sales/POS access is scoped to one
 * store at a time. Finance reporting is inherently cross-store — a Finance
 * user must be able to see the whole organization's numbers, or pick any one
 * store, without touching their global "active store" switcher. Reusing the
 * existing policies here would either wrongly 404/403 a legitimate Finance
 * request or (if "fixed" by loosening those policies) leak cross-store access
 * to every Sales/POS user too. So every Finance controller authorizes through
 * here instead: organization + explicit permission only, never the active
 * store.
 */
class FinanceAccessGuard
{
    public function authorizeView(User $user, Organization $organization): void
    {
        abort_unless($user->hasPermission($organization, 'finance.view'), 403);
    }

    public function authorizeReceivables(User $user, Organization $organization): void
    {
        abort_unless(
            $user->hasPermission($organization, 'finance.receivables.view')
                || $user->hasPermission($organization, 'finance.view'),
            403,
        );
    }

    public function authorizeExport(User $user, Organization $organization): void
    {
        abort_unless($user->hasPermission($organization, 'finance.export'), 403);
    }

    /**
     * Resolve an optional `store_id` request input into a Store guaranteed to
     * belong to the given organization — never trusting the id alone. `null`
     * means "all stores belonging to the organization."
     */
    public function resolveStore(Organization $organization, int|string|null $storeId): ?Store
    {
        if ($storeId === null || $storeId === '') {
            return null;
        }

        return Store::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($storeId)
            ->firstOrFail();
    }
}
