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
use Illuminate\Validation\ValidationException;

class OrganizationMembershipController extends Controller
{
    /**
     * Add an EXISTING user to the organization, either by numeric `user_id`
     * (original flow, kept for backward compatibility) or by `email` (used by
     * the Users & Access "add existing user" form). If no account exists for
     * the given email, this 422s — the caller should fall back to the invite
     * flow (see OrganizationInvitationController).
     */
    public function store(Request $request, Organization $organization, MembershipService $memberships): RedirectResponse
    {
        $this->authorize('create', [OrganizationMembership::class, $organization]);

        $data = $request->validate([
            'user_id' => ['required_without:email', 'nullable', 'integer', Rule::exists('users', 'id')],
            'email' => ['required_without:user_id', 'nullable', 'email', 'max:255'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        if (empty($data['user_id'])) {
            $user = User::query()->where('email', $data['email'])->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => 'No account exists for this email yet. Send an invitation instead.',
                ]);
            }
        } else {
            $user = User::query()->findOrFail($data['user_id']);
        }

        $memberships->addOrganizationMember(
            $request->user(),
            $organization,
            $user,
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
