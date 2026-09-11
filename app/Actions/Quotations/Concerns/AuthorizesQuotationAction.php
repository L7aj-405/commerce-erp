<?php

namespace App\Actions\Quotations\Concerns;

use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;

trait AuthorizesQuotationAction
{
    private function authorizeQuotationScope(User $actor, Organization $organization, Store $store, string $permission): void
    {
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $actor->active_store_id === $store->getKey()
            && $organization->status === 'active'
            && $store->organization_id === $organization->getKey()
            && $store->status === 'active'
            && $store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($organization, $permission),
            403,
        );
    }

    private function authorizeQuotation(User $actor, Quotation $quotation, string $permission): void
    {
        $this->authorizeQuotationScope($actor, $quotation->organization, $quotation->store, $permission);
    }
}
