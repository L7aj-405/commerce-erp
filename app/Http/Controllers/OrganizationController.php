<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\OrganizationCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return response()->json([
            'organization' => $organization->only(['id', 'name', 'status', 'settings']),
        ]);
    }

    public function store(Request $request, OrganizationCreator $creator): RedirectResponse
    {
        $this->authorize('create', Organization::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $creator->create($request->user(), $data['name'], $data['settings'] ?? []);

        return redirect()->route('platform.index');
    }

    public function update(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $organization);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $oldName = $organization->name;
        $organization->update(['name' => $data['name']]);

        $audit->record(
            'organization.updated',
            $request->user(),
            $organization,
            auditable: $organization,
            oldValues: ['name' => $oldName],
            newValues: ['name' => $organization->name],
        );

        return back();
    }

    public function updateSettings(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('updateSettings', $organization);

        $data = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $oldSettings = $organization->settings ?? [];
        $organization->update(['settings' => $data['settings']]);

        $audit->record(
            'organization.settings_changed',
            $request->user(),
            $organization,
            auditable: $organization,
            oldValues: ['settings' => $oldSettings],
            newValues: ['settings' => $data['settings']],
        );

        return back();
    }

    public function destroy(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $organization);

        $audit->record(
            'organization.deleted',
            $request->user(),
            $organization,
            auditable: $organization,
            oldValues: ['name' => $organization->name],
        );

        $organization->delete();

        return redirect()->route('platform.index');
    }
}
