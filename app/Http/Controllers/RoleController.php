<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\RolePermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function store(Request $request, ActiveTenantContext $context, AuditLogger $audit): RedirectResponse
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
        ]);

        $role = new Role;
        $role->organization_id = $organization->getKey();
        $role->name = $data['name'];
        $role->slug = $data['slug'] ?? Str::slug($data['name']);
        $role->is_system = false;
        $role->save();

        $audit->record(
            'role.created',
            $request->user(),
            $organization,
            auditable: $role,
            newValues: ['name' => $role->name, 'slug' => $role->slug],
        );

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
