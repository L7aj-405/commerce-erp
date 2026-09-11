<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-side invite-by-email: create and revoke. Acceptance (public, token
 * based) is handled separately by InvitationAcceptController.
 */
class OrganizationInvitationController extends Controller
{
    public function store(Request $request, Organization $organization, InvitationService $invitations): RedirectResponse
    {
        $this->authorize('create', [UserInvitation::class, $organization]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        $role = Role::query()->where('organization_id', $organization->getKey())->findOrFail($data['role_id']);

        $result = $invitations->invite($request->user(), $organization, $data['email'], $role);

        // The accept URL is deliberately also returned to the admin UI (flash),
        // not only emailed — SMTP may not be configured yet in every
        // environment, and the invite must remain usable regardless.
        return back()->with('success', 'Invitation envoyée.')->with('invitationUrl', $result['url']);
    }

    public function destroy(Request $request, UserInvitation $invitation, InvitationService $invitations): RedirectResponse
    {
        $this->authorize('delete', $invitation);
        $invitations->revoke($request->user(), $invitation);

        return back()->with('success', 'Invitation annulée.');
    }
}
