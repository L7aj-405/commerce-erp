<?php

namespace App\Actions\Procurement\Concerns;

use App\Models\Organization;
use App\Models\User;

trait AuthorizesProcurementAction
{
    private function authorizeProcurement(User $actor, Organization $organization, string $permission): void
    {
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $organization->status === 'active'
            && $actor->organizationMemberships()->where('organization_id', $organization->getKey())->where('status', 'active')->exists()
            && $actor->hasPermission($organization, $permission),
            403
        );
    }
}
