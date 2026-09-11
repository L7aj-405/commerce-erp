<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\RolePermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    /**
     * Create a new, always-editable (is_system = false) role.
     *
     * `preset_slug` is optional and purely descriptive: it records which
     * preset (config('platform.presets')) this role started from, for the UI
     * to display later. It is never consulted by hasPermission(), and future
     * edits to config('platform.presets') never retroactively change a role
     * created earlier from it.
     *
     * The initial permission set comes from `permission_ids` when given
     * (the Users & Access UI sends the matrix exactly as the admin left it,
     * whether that's the preset's defaults or a hand-tweaked selection);
     * otherwise, when `preset_slug` is given without `permission_ids`, it
     * falls back to that preset's full list. Either way the set is always
     * synced through RolePermissionService::sync — the same escalation guard
     * used everywhere else, so an actor can never come away from this with a
     * permission they didn't already hold themselves. Omitting both starts
     * the role ("Custom") with zero permissions.
     */
    public function store(Request $request, ActiveTenantContext $context, AuditLogger $audit, RolePermissionService $permissionService): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Role::class, $organization]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('roles')->where('organization_id', $organization->getKey()),
            ],
            'preset_slug' => ['nullable', 'string', Rule::in(array_keys(config('platform.presets', [])))],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);

        $role = DB::transaction(function () use ($request, $organization, $data, $audit, $permissionService) {
            $role = new Role;
            $role->organization_id = $organization->getKey();
            $role->name = $data['name'];
            $role->slug = $data['slug'] ?? Str::slug($data['name']);
            $role->is_system = false;
            $role->preset_slug = $data['preset_slug'] ?? null;
            $role->save();

            $audit->record(
                'role.created',
                $request->user(),
                $organization,
                auditable: $role,
                newValues: ['name' => $role->name, 'slug' => $role->slug, 'preset_slug' => $role->preset_slug],
            );

            $permissions = null;

            if (array_key_exists('permission_ids', $data)) {
                $permissions = Permission::query()->whereKey($data['permission_ids'])->get();
            } elseif ($role->preset_slug) {
                $presetKeys = config("platform.presets.{$role->preset_slug}.permissions", []);
                $actorKeys = $request->user()->permissionKeysFor($organization);
                $grantableKeys = array_values(array_intersect($presetKeys, $actorKeys));
                $permissions = Permission::query()->whereIn('key', $grantableKeys)->get();
            }

            if ($permissions !== null && $permissions->isNotEmpty()) {
                $permissionService->sync($request->user(), $role, $permissions);
            }

            return $role;
        });

        return back();
    }

    public function update(Request $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $role);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where('organization_id', $role->organization_id)->ignore($role),
            ],
        ]);

        $oldValues = $role->only(['name', 'slug']);
        $role->update($data);

        $audit->record(
            'role.updated',
            $request->user(),
            $role->organization,
            auditable: $role,
            oldValues: $oldValues,
            newValues: $role->only(['name', 'slug']),
        );

        return back();
    }

    public function permissions(Request $request, Role $role, RolePermissionService $service): RedirectResponse
    {
        $this->authorize('assignPermissions', $role);

        $data = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);

        $permissions = Permission::query()->whereKey($data['permission_ids'])->get();
        $service->sync($request->user(), $role, $permissions);

        return back();
    }

    public function destroy(Request $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $role);

        $organization = $role->organization;
        $values = $role->only(['name', 'slug']);
        $role->delete();

        $audit->record(
            'role.deleted',
            $request->user(),
            $organization,
            oldValues: $values,
        );

        return back();
    }
}
