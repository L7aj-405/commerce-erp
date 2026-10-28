<?php

namespace App\Actions\Procurement\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\Organization;
use App\Models\User;

trait AuthorizesProcurementAction
{
    use MatchesActorTenant;

    private function authorizeProcurement(User $actor, Organization $organization, string $permission): void
    {
        // Web: the browser's active organization. MCP: the connection's organization and user.
        abort_unless(
            $this->actorOrganizationMatches($actor, $organization)
            && $organization->status === 'active'
            && $actor->organizationMemberships()->where('organization_id', $organization->getKey())->where('status', 'active')->exists()
            && $actor->hasPermission($organization, $permission),
            403
        );
    }
}
