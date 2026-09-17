<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function destroy(Request $request, string $token, AuditLogger $audit): RedirectResponse
    {
        $currentId = $request->session()->getId();

        $session = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $currentId)
            ->get(['id'])
            ->first(fn ($row) => hash_equals(self::opaqueToken($row->id), $token));

        if ($session) {
            DB::table('sessions')->where('id', $session->id)->delete();
            $audit->record('auth.session_revoked', $request->user(), $request->user()->activeOrganization, auditable: $request->user());
        }

        return back()->with('success', 'Session révoquée.');
    }

    public function destroyOthers(Request $request, AuditLogger $audit): RedirectResponse
    {
        DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $audit->record('auth.other_sessions_revoked', $request->user(), $request->user()->activeOrganization, auditable: $request->user());

        return back()->with('success', 'Les autres sessions ont été déconnectées.');
    }
}
