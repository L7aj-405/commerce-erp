<?php

namespace App\Actions\Contacts\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait AuthorizesContactAction
{
    use MatchesActorTenant;

    /** Same-organization actor with an active membership holding every given permission, else 403. */
    private function authorizeContact(User $actor, Organization $organization, string ...$permissions): void
    {
        abort_unless(
            $this->actorOrganizationMatches($actor, $organization)
            && $organization->status === 'active'
            && $actor->organizationMemberships()->where('organization_id', $organization->getKey())->where('status', 'active')->exists(),
            403
        );
        foreach ($permissions as $permission) {
            abort_unless($actor->hasPermission($organization, $permission), 403);
        }
    }

    /** A party of another organization is indistinguishable from a missing one. */
    private function assertPartyInOrganization(Party $party, Organization $organization): void
    {
        abort_unless((int) $party->organization_id === (int) $organization->getKey(), 404);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
