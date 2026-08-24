<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MembershipService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActiveTenantContext $context,
    ) {}

    public function addOrganizationMember(User $actor, Organization $organization, User $user, Role $role): OrganizationMembership
    {
        abort_unless($actor->hasPermission($organization, 'members.create'), 403);
        $this->assertRoleAssignable($actor, $organization, $role);

        if (OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['user_id' => 'This user is already an organization member.']);
        }

        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->getKey();
        $membership->user_id = $user->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = 'active';
        $membership->save();

        $this->audit->record(
            'organization_membership.created',
            $actor,
            $organization,
            auditable: $membership,
            newValues: ['user_id' => $user->getKey(), 'role_id' => $role->getKey(), 'status' => 'active'],
        );

        return $membership;
    }

    public function changeRole(User $actor, OrganizationMembership $membership, Role $role): void
    {
        abort_unless($actor->hasPermission($membership->organization_id, 'members.update'), 403);
        abort_if($membership->user_id === $actor->getKey(), 403, 'You cannot change your own role.');
        abort_if($membership->user_id === $membership->organization->owner_id, 403, 'The owner role cannot be changed.');

        $this->assertRoleAssignable($actor, $membership->organization, $role);

        $oldRoleId = $membership->role_id;
        $membership->role_id = $role->getKey();
        $membership->save();

        $this->audit->record(
            'organization_membership.role_changed',
            $actor,
            $membership->organization,
            auditable: $membership,
            oldValues: ['role_id' => $oldRoleId],
            newValues: ['role_id' => $role->getKey()],
        );
    }

    public function changeStatus(User $actor, OrganizationMembership $membership, string $status): void
    {
        abort_unless($actor->hasPermission($membership->organization_id, 'members.update'), 403);
        abort_if($membership->user_id === $actor->getKey(), 403, 'You cannot change your own membership status.');
        abort_if($membership->user_id === $membership->organization->owner_id, 403, 'The owner membership cannot be suspended.');

        $oldStatus = $membership->status;
        $membership->status = $status;
        $membership->save();

        if ($status !== 'active' && $membership->user->active_organization_id === $membership->organization_id) {
            $member = $membership->user;
            $member->active_organization_id = null;
            $member->active_store_id = null;
            $member->save();
        }

        $this->audit->record(
            'organization_membership.status_changed',
            $actor,
            $membership->organization,
            auditable: $membership,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $status],
        );
    }

    public function removeOrganizationMember(User $actor, OrganizationMembership $membership): void
    {
        abort_unless($actor->hasPermission($membership->organization_id, 'members.delete'), 403);
        abort_if($membership->user_id === $actor->getKey(), 403, 'You cannot remove yourself.');
        abort_if($membership->user_id === $membership->organization->owner_id, 403, 'The owner cannot be removed.');

        $organization = $membership->organization;
        $values = ['user_id' => $membership->user_id, 'role_id' => $membership->role_id];
        $membership->delete();

        $this->audit->record(
            'organization_membership.deleted',
            $actor,
            $organization,
            oldValues: $values,
        );
    }

    public function addStoreMember(User $actor, Store $store, User $user): StoreMembership
    {
        abort_unless(
            $this->context->organization()?->getKey() === $store->organization_id
                && $actor->hasPermission($store->organization_id, 'store-memberships.manage')
                && $store->memberships()->where('user_id', $actor->getKey())->exists(),
            403,
        );

        $activeOrganizationMembership = OrganizationMembership::query()
            ->where('organization_id', $store->organization_id)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->exists();

        throw_unless($activeOrganizationMembership, ValidationException::withMessages([
            'user_id' => 'The user must be an active member of the store organization.',
        ]));

        if (StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['user_id' => 'This user is already a store member.']);
        }

        $membership = new StoreMembership;
        $membership->organization_id = $store->organization_id;
        $membership->store_id = $store->getKey();
        $membership->user_id = $user->getKey();
        $membership->save();

        $this->audit->record(
            'store_membership.created',
            $actor,
            $store->organization,
            $store,
            $membership,
            newValues: ['user_id' => $user->getKey()],
        );

        return $membership;
    }

    public function removeStoreMember(User $actor, StoreMembership $membership): void
    {
        abort_unless(
            $this->context->organization()?->getKey() === $membership->organization_id
                && $actor->hasPermission($membership->organization_id, 'store-memberships.manage')
                && $membership->store->memberships()->where('user_id', $actor->getKey())->exists(),
            403,
        );

        abort_if($membership->user_id === $actor->getKey(), 403, 'You cannot remove your own store access.');

        $organization = $membership->organization;
        $store = $membership->store;
        $values = ['user_id' => $membership->user_id];
        $membership->delete();

        $this->audit->record(
            'store_membership.deleted',
            $actor,
            $organization,
            $store,
            oldValues: $values,
        );
    }

    private function assertRoleAssignable(User $actor, Organization $organization, Role $role): void
    {
        abort_unless($role->organization_id === $organization->getKey(), 422, 'The role must belong to the organization.');
        abort_if($role->slug === 'owner', 403, 'The owner role cannot be assigned.');

        $actorPermissions = $actor->permissionKeysFor($organization);
        $rolePermissions = $role->permissions()->pluck('key')->all();

        abort_if(array_diff($rolePermissions, $actorPermissions) !== [], 403, 'You cannot assign a role with permissions you do not hold.');
    }
}
