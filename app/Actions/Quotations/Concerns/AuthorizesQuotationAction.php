<?php

namespace App\Actions\Quotations\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxQuotationRules;

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
        abort_unless((string) $quotation->sandbox_id === (string) app(SandboxContext::class)->id(), 404);
        if ($quotation->sandbox_id !== null) {
            abort_unless(SandboxQuotationRules::allows($quotation->sandbox_id, $permission), 403, 'TEST: transition indisponible.');
        }
        $this->authorizeQuotationScope($actor, $quotation->organization, $quotation->store, $permission);
    }
}
