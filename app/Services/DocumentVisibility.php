<?php

namespace App\Services;

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * W5.1 canonical "which store documents may this user see" scope, for every
 * surface that lists documents outside their own store-scoped pages
 * (customer profile, activity timeline, MCP customer balance).
 *
 * A store document is visible when it belongs to the organization, the user
 * holds the document's view permission there, and the document's store is an
 * ACTIVE store the user is a member of — the same rule as the store-scoped
 * route binding (ScopesToActiveStore), ActiveTenantContext and the safe
 * cross-store opener (StoreDocumentOpener). Seeing a customer never implies
 * seeing all of its documents. Without the permission the scope matches
 * nothing, so lists, counts and sums agree.
 *
 * Per-instance memo (one request / one job); never shared across requests.
 */
final class DocumentVisibility
{
    /** @var array<class-string, string> */
    public const PERMISSIONS = [
        Quotation::class => 'quotations.view',
        SalesOrder::class => 'sales_orders.view',
        Invoice::class => 'invoices.view',
        DeliveryNote::class => 'delivery_notes.view',
        Payment::class => 'payments.view',
    ];

    /** @var array<string, list<string>> */
    private array $permissionKeys = [];

    /** @var array<string, list<int>> */
    private array $storeIds = [];

    public function allows(User $user, Organization $organization, string $permission): bool
    {
        return in_array($permission, $this->permissionKeys[$user->getKey().':'.$organization->getKey()] ??= $user->permissionKeysFor($organization), true);
    }

    /** @param class-string $model */
    public function canView(User $user, Organization $organization, string $model): bool
    {
        return $this->allows($user, $organization, self::PERMISSIONS[$model]);
    }

    /** Active stores of the organization the user is a member of. @return list<int> */
    public function storeIds(User $user, Organization $organization): array
    {
        return $this->storeIds[$user->getKey().':'.$organization->getKey()] ??= StoreMembership::query()
            ->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())
            ->whereHas('store', fn (Builder $store) => $store->where('status', 'active'))
            ->pluck('store_id')->map(fn ($id) => (int) $id)->all();
    }

    public function hasStore(User $user, Organization $organization, int|string|null $storeId): bool
    {
        return $storeId !== null && in_array((int) $storeId, $this->storeIds($user, $organization), true);
    }

    /**
     * Documents of one kind the user may see (SQL scope — callers add their own filters).
     *
     * @param  class-string  $model
     */
    public function visible(User $user, Organization $organization, string $model): Builder
    {
        $allowed = $this->canView($user, $organization, $model);

        return $model::query()
            ->where($model::make()->qualifyColumn('organization_id'), $organization->getKey())
            ->whereIn($model::make()->qualifyColumn('store_id'), $allowed ? ($this->storeIds($user, $organization) ?: [0]) : [0]);
    }
}
