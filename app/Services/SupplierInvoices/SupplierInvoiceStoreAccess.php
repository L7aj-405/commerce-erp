<?php

namespace App\Services\SupplierInvoices;

use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;

/**
 * Store-level access for supplier invoices, decided ONLY by explicit `store_memberships` rows.
 *
 * There is deliberately no Owner/Admin bypass: an organization owner who is not a member of a store cannot touch that
 * store's supplier invoices (owners obtain store access through StoreCreator / MembershipService::addStoreMember like
 * everybody else). Permission checks (`supplier_invoices.*`) and organization/tenant checks stay in the policy/action
 * layer; this class answers "is the user a member of this store (and is it usable for this operation)".
 *
 * - create / edit / approve: membership in an ACTIVE store (a draft cannot be started or finalized in a closed store);
 * - view / void: membership regardless of the store status (history stays readable and a mistaken invoice can still be
 *   voided after the store was deactivated).
 */
final class SupplierInvoiceStoreAccess
{
    public function canCreate(User $user, Store $store): bool
    {
        return $this->isActive($store) && $this->isMember($user, $store);
    }

    public function canEdit(User $user, Store $store): bool
    {
        return $this->canCreate($user, $store);
    }

    public function canApprove(User $user, Store $store): bool
    {
        return $this->canCreate($user, $store);
    }

    public function canView(User $user, Store $store): bool
    {
        return $this->isMember($user, $store);
    }

    public function canVoid(User $user, Store $store): bool
    {
        return $this->isMember($user, $store);
    }

    /**
     * Stores (any status) of the organization the user is an explicit member of, for scoping lists.
     *
     * @return list<int>
     */
    public function visibleStoreIds(User $user, Organization $organization): array
    {
        return StoreMembership::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function isActive(Store $store): bool
    {
        return $store->status === 'active';
    }

    private function isMember(User $user, Store $store): bool
    {
        return StoreMembership::query()
            ->where('user_id', $user->getKey())
            ->where('store_id', $store->getKey())
            ->where('organization_id', $store->organization_id)
            ->exists();
    }
}
