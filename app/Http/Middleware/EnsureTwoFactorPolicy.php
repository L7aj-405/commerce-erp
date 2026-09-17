<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §E7 — organization-level mandatory 2FA, PLUS (Sprint 1.1 §7)
 * privileged-role mandatory 2FA: when `require_2fa_for_privileged_roles` is
 * on for the organization, its owner/admin must enroll even if the blanket
 * `require_2fa` is off, since those roles can manage members, roles,
 * integrations and settings for the whole tenant.
 * `require_2fa_for_privileged_roles` defaults to ON for every newly created
 * organization outside local/testing (see OrganizationCreator) — "prefer
 * enforcing it for privileged accounts by default" — but is never
 * retroactively turned on for an organization that already existed before
 * that column was introduced (see the migration's doc); enabling it for an
 * older organization is a deliberate, explicit action.
 *
 * Either condition redirects to Account Settings > Security instead of the
 * requested business route — except the handful of routes needed to
 * actually enroll, log out, or finish email verification, which must stay
 * reachable or the policy becomes an inescapable lock. Recovery if the
 * authenticator is lost: the single-use recovery codes generated at
 * enrollment (see RecoveryCodeService) — never a support-side bypass. Tenant
 * isolation is untouched: this only ever reads the already-resolved active
 * organization/membership, never another tenant's data.
 */
class EnsureTwoFactorPolicy
{
    /** @var list<string> */
    private const EXEMPT_ROUTES = [
        'security.edit',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.recovery-codes.regenerate',
        'security.sessions.destroy',
        'security.sessions.destroy-others',
        'password.confirm',
        'logout',
        'verification.notice',
        'verification.verify',
        'verification.send',
        'context.organization',
        'context.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs(...self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        $organization = $user->activeOrganization;

        if ($organization && ! $user->hasEnabledTwoFactorAuthentication()) {
            if ($organization->requiresTwoFactor()) {
                return redirect()->route('security.edit')->with(
                    'status',
                    'Votre organisation exige la double authentification. Activez-la pour continuer.',
                );
            }

            if ($organization->requiresTwoFactorForPrivilegedRoles() && $user->hasPrivilegedRoleIn($organization)) {
                return redirect()->route('security.edit')->with(
                    'status',
                    'Votre rôle (propriétaire ou administrateur) exige la double authentification. Activez-la pour continuer.',
                );
            }
        }

        return $next($request);
    }
}
