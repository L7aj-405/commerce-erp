<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\FollowUps\FollowUpAssignmentReleaser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RolePermissionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FollowUpAssignmentReleaser $followUps,
    ) {}

    /** @param Collection<int, Permission> $permissions */
    public function sync(User $actor, Role $role, Collection $permissions): void
    {
        abort_unless($actor->hasPermission($role->organization_id, 'roles.assign-permissions'), 403);
        abort_if($role->is_system, 403, 'System role permissions cannot be changed.');

        $actorPermissions = $actor->permissionKeysFor($role->organization);
        $requestedPermissions = $permissions->pluck('key')->all();

        abort_if(array_diff($requestedPermissions, $actorPermissions) !== [], 403, 'You cannot assign permissions you do not hold.');

        $oldPermissions = $role->permissions()->pluck('key')->sort()->values()->all();
        $sync = $permissions->mapWithKeys(
            fn (Permission $permission) => [$permission->getKey() => ['organization_id' => $role->organization_id]],
        )->all();

        DB::transaction(function () use ($actor, $role, $sync, $oldPermissions, $requestedPermissions) {
            $role->permissions()->sync($sync);

            $this->audit->record(
                'role.permissions_changed',
                $actor,
                $role->organization,
                auditable: $role,
                oldValues: ['permissions' => $oldPermissions],
                newValues: ['permissions' => collect($requestedPermissions)->sort()->values()->all()],
            );

            // Members of a role that lost follow_ups.view can no longer hold open work.
            if (in_array('follow_ups.view', $oldPermissions, true) && ! in_array('follow_ups.view', $requestedPermissions, true)) {
                $this->followUps->releaseDeparted($role->organization, $actor, 'role_permissions_changed');
            }
        });
    }
}
