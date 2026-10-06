<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Notifications\OperationalNotificationProducer;
use App\Services\Security\SessionPresentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 1.1 §8 — account-level session visibility/revocation. Relies on the
 * `database` session driver (this app's actual config — see
 * DEPLOYMENT_CHECKLIST.md §11) storing one row per active session, keyed by
 * `user_id`. The raw `sessions.id` column IS the literal session identifier —
 * exposing it to the client would hand out something equivalent to a session
 * cookie for that device, so every row is addressed to the frontend only by
 * an opaque, one-way token (see {@see opaqueToken()}), never the real id.
 */
class ActiveSessionController extends Controller
{
    public static function opaqueToken(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 20);
    }

    public function index(Request $request, SessionPresentationService $sessions): Response
    {
        return Inertia::render('Settings/Sessions', [
            'sessions' => $sessions->forUser($request->user(), $request->session()->getId()),
        ]);
    }

    public function destroy(
        Request $request,
        string $token,
        AuditLogger $audit,
        OperationalNotificationProducer $notifications,
    ): RedirectResponse
    {
        $currentId = $request->session()->getId();

        $session = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $currentId)
            ->get(['id'])
            ->first(fn ($row) => hash_equals(self::opaqueToken($row->id), $token));

        if ($session) {
            DB::table('sessions')->where('id', $session->id)->delete();
            $log = $audit->record('auth.session_revoked', $request->user(), $request->user()->activeOrganization, auditable: $request->user());
            if ($organization = $request->user()->activeOrganization) {
                $notifications->sessionSecurity($request->user(), $organization, $log->getKey(), 'revoked');
            }
        }

        return back()->with('success', 'Session révoquée.');
    }

    public function destroyOthers(
        Request $request,
        AuditLogger $audit,
        OperationalNotificationProducer $notifications,
    ): RedirectResponse
    {
        $count = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $log = $audit->record(
            'auth.other_sessions_revoked',
            $request->user(),
            $request->user()->activeOrganization,
            auditable: $request->user(),
            newValues: ['revoked_count' => $count],
        );
        if ($organization = $request->user()->activeOrganization) {
            $notifications->sessionSecurity($request->user(), $organization, $log->getKey(), 'revoked_all');
        }

        return back()->with('success', 'Les autres sessions ont été déconnectées.');
    }
}
