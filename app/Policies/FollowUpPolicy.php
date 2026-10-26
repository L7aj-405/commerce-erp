<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\FollowUps\FollowUpAccess;

/** Web adapter over FollowUpAccess, pinned to the active tenant context. */
class FollowUpPolicy
{
    public function __construct(
        private readonly ActiveTenantContext $context,
        private readonly FollowUpAccess $access,
    ) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->inContext($organization->getKey()) && $this->access->canViewAny($user, $organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->inContext($organization->getKey()) && $this->access->canCreate($user, $organization);
    }

    public function view(User $user, FollowUp $followUp): bool
    {
        return $this->inContext($followUp->organization_id) && $this->access->canView($user, $followUp);
    }

    public function update(User $user, FollowUp $followUp): bool
    {
        return $this->inContext($followUp->organization_id) && $this->access->canManage($user, $followUp);
    }

    private function inContext(int|string $organizationId): bool
    {
        return (int) $this->context->organization()?->getKey() === (int) $organizationId;
    }
}
