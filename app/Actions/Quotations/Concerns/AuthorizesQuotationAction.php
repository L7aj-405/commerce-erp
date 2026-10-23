<?php

namespace App\Actions\Quotations\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;

trait AuthorizesQuotationAction
{
    use MatchesActorTenant;

    private function authorizeQuotationScope(User $actor, Organization $organization, Store $store, string $permission): void
    {
        abort_unless(
            $this->actorTenantMatches($actor, $organization, $store)
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
