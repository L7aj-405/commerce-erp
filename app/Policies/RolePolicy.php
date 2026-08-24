<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission($role->organization_id, 'roles.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return ! $role->is_system
            && $user->hasPermission($role->organization_id, 'roles.update');
    }

    public function assignPermissions(User $user, Role $role): bool
    {
        return ! $role->is_system
            && $user->hasPermission($role->organization_id, 'roles.assign-permissions');
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system
            && $user->hasPermission($role->organization_id, 'roles.delete')
            && ! $role->memberships()->exists();
    }
}
