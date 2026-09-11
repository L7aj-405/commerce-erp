<?php

namespace App\Services;

use App\Mail\UserInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin-initiated invite-by-email. Complements MembershipService (which
 * attaches an EXISTING User to an organization): this creates a brand-new
 * teammate's account at acceptance time when none exists yet.
 *
 * The invitation is organization-scoped, one-time, and expiring. Only the
 * sha256 hash of the random token is ever persisted — the plaintext token
 * lives solely in the (mailed and admin-visible) accept URL.
 */
class InvitationService
{
    private const TTL_DAYS = 7;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{invitation: UserInvitation, url: string} */
    public function invite(User $actor, Organization $organization, string $email, Role $role): array
    {
        abort_unless($actor->hasPermission($organization, 'members.create'), 403);
        abort_unless($role->organization_id === $organization->getKey(), 422, 'The role must belong to the organization.');
        abort_if($role->slug === 'owner', 403, 'The owner role cannot be assigned.');

        $actorPermissions = $actor->permissionKeysFor($organization);
        $rolePermissions = $role->permissions()->pluck('key')->all();
        abort_if(
            array_diff($rolePermissions, $actorPermissions) !== [],
            403,
            'You cannot invite a member with a role that grants permissions you do not hold.',
        );

        if (
            OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->whereHas('user', fn ($query) => $query->where('email', $email))
                ->exists()
        ) {
            throw ValidationException::withMessages(['email' => 'This user is already an organization member.']);
        }

        [$invitation, $plainToken] = DB::transaction(function () use ($actor, $organization, $email, $role) {
            // Superseded by this new invite: at most one live (pending, unexpired)
            // invitation per email per organization.
            UserInvitation::query()
                ->where('organization_id', $organization->getKey())
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->delete();

            $plainToken = Str::random(48);

            $invitation = new UserInvitation;
            $invitation->organization_id = $organization->getKey();
            $invitation->email = $email;
            $invitation->role_id = $role->getKey();
            $invitation->invited_by_user_id = $actor->getKey();
            $invitation->token_hash = hash('sha256', $plainToken);
            $invitation->expires_at = now()->addDays(self::TTL_DAYS);
            $invitation->save();

            $this->audit->record(
                'user_invitation.created',
                $actor,
                $organization,
                auditable: $invitation,
                newValues: ['email' => $email, 'role_id' => $role->getKey(), 'expires_at' => $invitation->expires_at->toIso8601String()],
            );

            return [$invitation, $plainToken];
        });

        $url = URL::route('invitations.accept', ['token' => $plainToken]);

        $this->sendMailSafely($invitation, $organization, $role, $url);

        return ['invitation' => $invitation, 'url' => $url];
    }

    public function revoke(User $actor, UserInvitation $invitation): void
    {
        abort_unless($actor->hasPermission($invitation->organization_id, 'members.delete'), 403);
        abort_if($invitation->isAccepted(), 422, 'This invitation was already accepted.');

        $organization = $invitation->organization;
        $values = ['email' => $invitation->email, 'role_id' => $invitation->role_id];
        $invitation->delete();

        $this->audit->record('user_invitation.revoked', $actor, $organization, oldValues: $values);
    }

    /**
     * @param  array{name?: string, password?: string}  $newAccount  Required only
     *                                                               when no User exists yet for the invited email.
     */
    public function accept(string $plainToken, array $newAccount = []): OrganizationMembership
    {
        $invitation = $this->findByToken($plainToken);

        abort_if(! $invitation, 404);
        abort_if($invitation->isAccepted(), 410, 'This invitation has already been used.');
        abort_if($invitation->isExpired(), 410, 'This invitation has expired.');

        return DB::transaction(function () use ($invitation, $newAccount) {
            // Re-check inside the transaction against a locked row: two
            // concurrent accept requests for the same token must not both succeed.
            $locked = UserInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->isAccepted(), 410, 'This invitation has already been used.');
            abort_if($locked->isExpired(), 410, 'This invitation has expired.');

            $user = User::query()->where('email', $locked->email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $newAccount['name'] ?? Str::before($locked->email, '@'),
                    'email' => $locked->email,
                    'password' => $newAccount['password'] ?? Str::password(40),
                ]);
            }

            $alreadyMember = OrganizationMembership::query()
                ->where('organization_id', $locked->organization_id)
                ->where('user_id', $user->getKey())
                ->exists();

            if ($alreadyMember) {
                $locked->accepted_at = now();
                $locked->accepted_by_user_id = $user->getKey();
                $locked->save();

                throw ValidationException::withMessages(['email' => 'This user is already a member of the organization.']);
            }

            $membership = new OrganizationMembership;
            $membership->organization_id = $locked->organization_id;
            $membership->user_id = $user->getKey();
            $membership->role_id = $locked->role_id;
            $membership->status = 'active';
            $membership->save();

            $locked->accepted_at = now();
            $locked->accepted_by_user_id = $user->getKey();
            $locked->save();

            if (! $user->active_organization_id) {
                $user->active_organization_id = $locked->organization_id;
                $user->save();
            }

            $this->audit->record(
                'user_invitation.accepted',
                $user,
                $locked->organization,
                auditable: $membership,
                newValues: ['user_id' => $user->getKey(), 'role_id' => $locked->role_id],
            );

            return $membership;
        });
    }

    public function findByToken(string $plainToken): ?UserInvitation
    {
        return UserInvitation::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
    }

    private function sendMailSafely(UserInvitation $invitation, Organization $organization, Role $role, string $url): void
    {
        try {
            Mail::to($invitation->email)->send(
                new UserInvitationMail($organization->name, $role->name, $url, $invitation->expires_at),
            );
        } catch (Throwable $e) {
            // Never fail invitation creation because outbound mail is
            // unavailable/misconfigured — the admin UI always exposes the
            // accept URL directly as a fallback (see InvitationService::invite
            // callers / Settings/UsersAccess UI).
            report($e);
        }
    }
}
