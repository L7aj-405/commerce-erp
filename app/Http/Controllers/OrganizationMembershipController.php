<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationMembershipController extends Controller
{
    public function store(Request $request, Organization $organization, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('create', [OrganizationMembership::class, $organization]);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        $memberships->addOrganizationMember(
            $request->user(),
            $organization,
            User::query()->findOrFail($data['user_id']),
            Role::query()->where('organization_id', $organization->getKey())->findOrFail($data['role_id']),
        );

        return back();
    }

    public function update(Request $request, OrganizationMembership $membership, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('update', $membership);

        $data = $request->validate([
            'role_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('organization_id', $membership->organization_id),
            ],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
        ]);

        if (array_key_exists('role_id', $data)) {
            $role = Role::query()
                ->where('organization_id', $membership->organization_id)
                ->findOrFail($data['role_id']);
            $memberships->changeRole($request->user(), $membership, $role);
        }

        if (array_key_exists('status', $data)) {
            $memberships->changeStatus($request->user(), $membership, $data['status']);
        }

        return back();
    }

    public function destroy(Request $request, OrganizationMembership $membership, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('delete', $membership);
        $memberships->removeOrganizationMember($request->user(), $membership);

        return back();
    }
}
