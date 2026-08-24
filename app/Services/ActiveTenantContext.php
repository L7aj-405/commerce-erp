<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActiveTenantContext
{
    private ?Organization $organization = null;

    private ?Store $store = null;

    public function resolve(?User $user): void
    {
        $this->organization = null;
        $this->store = null;

        if (! $user) {
            return;
        }

        $membership = $user->active_organization_id
            ? OrganizationMembership::query()
                ->with('organization')
                ->where('organization_id', $user->active_organization_id)
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
                ->first()
            : null;

        if (! $membership) {
            $this->clearInvalidContext($user);

            return;
        }

        $this->organization = $membership->organization;

        if (! $user->active_store_id) {
            return;
        }

        $this->store = Store::query()
            ->whereKey($user->active_store_id)
            ->where('organization_id', $this->organization->getKey())
            ->where('status', 'active')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey()))
            ->first();

        if (! $this->store) {
            $user->active_store_id = null;
            $user->saveQuietly();
        }
    }

    public function activateOrganization(User $user, Organization $organization): void
    {
        $authorized = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->exists();

        abort_unless($authorized, 403, 'You cannot access this organization.');

        $user->active_organization_id = $organization->getKey();
        $user->active_store_id = null;
        $user->save();

        $this->resolve($user->fresh());
    }

    public function activateStore(User $user, Store $store): void
    {
        $this->resolve($user);
        $organization = $this->organizationOrFail();

        $authorized = $store->organization_id === $organization->getKey()
            && $store->status === 'active'
            && $store->memberships()->where('user_id', $user->getKey())->exists();

        abort_unless($authorized, 403, 'You cannot access this store.');

        $user->active_store_id = $store->getKey();
        $user->save();

        $this->store = $store;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function organizationOrFail(): Organization
    {
        if (! $this->organization) {
            throw new HttpException(409, 'Select an active organization.');
        }

        return $this->organization;
    }

    public function store(): ?Store
    {
        return $this->store;
    }

    public function storeOrFail(): Store
    {
        if (! $this->store) {
            throw new HttpException(409, 'Select an active store.');
        }

        return $this->store;
    }

    private function clearInvalidContext(User $user): void
    {
        if ($user->active_organization_id || $user->active_store_id) {
            $user->active_organization_id = null;
            $user->active_store_id = null;
            $user->saveQuietly();
        }
    }
}
