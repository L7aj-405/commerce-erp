<?php

namespace App\Services\Pos;

use App\Enums\WarehouseStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Which inventory locations a Store's POS may sell from.
 *
 * The SELLING Store is the active Store context and never changes with the
 * location. The POS location is only the cart's inventory anchor (where stock is
 * taken first); allocation may still draw on every active location of the
 * organisation, and a shortage still goes to sourcing.
 *
 * Eligible POS locations of a Store:
 *  - the active locations that physically belong to it (`warehouses.store_id`);
 *  - active storage-only depots explicitly opened to direct sales
 *    (`allow_direct_sales`);
 *  - backward compatibility: a Store with NO location of its own and no default
 *    location configured keeps the legacy behaviour (every active location of
 *    the organisation) until an administrator links its locations.
 *
 * Default location: the Store's `default_warehouse_id`, else the member's own
 * POS default, else the first eligible location — always an eligible one.
 */
final class PosLocations
{
    /** @return Collection<int, Warehouse> */
    public function eligible(Organization $organization, Store $store): Collection
    {
        $active = Warehouse::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', WarehouseStatus::Active->value)
            ->orderBy('name')
            ->get();

        $configured = $store->default_warehouse_id !== null
            || $active->contains(fn (Warehouse $w) => (int) $w->store_id === (int) $store->getKey());
        if (! $configured) {
            return $active;
        }

        return $active->filter(fn (Warehouse $w) => (int) $w->store_id === (int) $store->getKey()
            || ($w->store_id === null && $w->allow_direct_sales)
            || (int) $w->getKey() === (int) $store->default_warehouse_id)->values();
    }

    public function isEligible(Organization $organization, Store $store, Warehouse $warehouse): bool
    {
        return $this->eligible($organization, $store)->contains(fn (Warehouse $w) => (int) $w->getKey() === (int) $warehouse->getKey());
    }

    /** An eligible active location of this Store's POS, or a 422. */
    public function assertEligible(Organization $organization, Store $store, int $warehouseId): Warehouse
    {
        $warehouse = $this->eligible($organization, $store)->first(fn (Warehouse $w) => (int) $w->getKey() === $warehouseId);
        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Cet emplacement de stock n’est pas un emplacement de vente de ce magasin.',
            ]);
        }

        return $warehouse;
    }

    public function defaultId(Organization $organization, Store $store, User $user): ?int
    {
        $eligible = $this->eligible($organization, $store)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $memberDefault = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->value('default_warehouse_id');

        foreach ([$store->default_warehouse_id, $memberDefault] as $candidate) {
            if ($candidate !== null && in_array((int) $candidate, $eligible, true)) {
                return (int) $candidate;
            }
        }

        return $eligible[0] ?? null;
    }

    /** The member's own configured POS default, when it is eligible for this Store. */
    public function memberDefaultId(Organization $organization, Store $store, User $user): ?int
    {
        $memberDefault = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->value('default_warehouse_id');

        return $memberDefault !== null && $this->eligible($organization, $store)->contains('id', (int) $memberDefault)
            ? (int) $memberDefault
            : null;
    }

    /** "store" (belongs to a Store) or "depot" (storage-only). */
    public static function kind(Warehouse $warehouse): string
    {
        return $warehouse->store_id === null ? 'depot' : 'store';
    }
}
