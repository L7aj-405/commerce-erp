<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserInvitation;

class UserInvitationPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'members.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'members.create');
    }

    public function delete(User $user, UserInvitation $invitation): bool
    {
        return $user->hasPermission($invitation->organization_id, 'members.delete');
    }
}
