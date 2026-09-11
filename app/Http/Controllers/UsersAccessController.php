<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StoreMembership;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Users & Access. Read-only page assembly for the org-scoped
 * members / roles / permission-matrix / invitations admin UI. Every mutation
 * it triggers goes through the existing OrganizationMembershipController,
 * RoleController, StoreMembershipController and the new
 * OrganizationInvitationController — this controller renders, it never writes.
 */
class UsersAccessController extends Controller
{
    public function __invoke(Request $request, Organization $organization): Response
    {
        $this->authorize('viewAny', [OrganizationMembership::class, $organization]);

        $user = $request->user();
        $canViewRoles = $user->hasPermission($organization, 'roles.view');
        $canManageMembers = $user->hasPermission($organization, 'members.create');
        $canManageStoreAccess = $user->hasPermission($organization, 'store-memberships.manage');

        $storesByUser = StoreMembership::query()
            ->where('organization_id', $organization->getKey())
            ->with('store:id,name,code')
            ->get()
            ->groupBy('user_id');

        $memberships = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->with(['user:id,name,email', 'role:id,name,slug,is_system'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (OrganizationMembership $membership) => [
                'id' => $membership->id,
                'user' => $membership->user->only(['id', 'name', 'email']),
                'role' => $membership->role->only(['id', 'name', 'slug', 'is_system']),
                'status' => $membership->status,
                'is_owner' => $membership->user_id === $organization->owner_id,
                'store_memberships' => ($storesByUser->get($membership->user_id) ?? collect())
                    ->map(fn (StoreMembership $sm) => [
                        'id' => $sm->id,
                        'store' => $sm->store->only(['id', 'name', 'code']),
                    ])
                    ->values(),
            ]);

        $roles = $canViewRoles
            ? Role::query()
                ->where('organization_id', $organization->getKey())
                ->withCount('memberships')
                ->with('permissions:key')
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'is_system' => $role->is_system,
                    'preset_slug' => $role->preset_slug,
                    'members_count' => $role->memberships_count,
                    'permissions' => $role->permissions->pluck('key')->sort()->values(),
                ])
            : collect();

        $invitations = $canManageMembers
            ? UserInvitation::query()
                ->where('organization_id', $organization->getKey())
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->with('role:id,name')
                ->orderByDesc('created_at')
                ->get(['id', 'email', 'role_id', 'expires_at', 'created_at'])
                ->map(fn (UserInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->only(['id', 'name']),
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'created_at' => $invitation->created_at->toIso8601String(),
                ])
            : collect();

        $permissions = Permission::query()->orderBy('key')->get(['id', 'key', 'name']);
        $permissionGroups = $permissions
            ->groupBy(fn (Permission $permission) => Str::before($permission->key, '.'))
            ->map(fn ($group) => $group->map(fn (Permission $permission) => $permission->only(['id', 'key', 'name']))->values())
            ->sortKeys();

        $stores = $organization->stores()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);

        return Inertia::render('Settings/UsersAccess/Index', [
            'organization' => $organization->only(['id', 'name']),
            'memberships' => $memberships,
            'roles' => $roles,
            'invitations' => $invitations,
            'permissionGroups' => $permissionGroups,
            'presets' => collect(config('platform.presets', []))->map(fn ($preset, $slug) => [
                'slug' => $slug,
                'name' => $preset['name'],
                'permissions' => $preset['permissions'],
            ])->values(),
            'stores' => $stores,
            'can' => [
                'viewRoles' => $canViewRoles,
                'createRole' => $user->hasPermission($organization, 'roles.create'),
                'assignPermissions' => $user->hasPermission($organization, 'roles.assign-permissions'),
                'manageMembers' => $canManageMembers,
                'updateMembers' => $user->hasPermission($organization, 'members.update'),
                'deleteMembers' => $user->hasPermission($organization, 'members.delete'),
                'manageStoreAccess' => $canManageStoreAccess,
            ],
        ]);
    }
}
