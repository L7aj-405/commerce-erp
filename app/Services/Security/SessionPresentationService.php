<?php

namespace App\Services\Security;

use App\Http\Controllers\Settings\ActiveSessionController;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

final class SessionPresentationService
{
    /** @return Collection<int, array<string, mixed>> */
    public function forUser(User $user, string $currentSessionId): Collection
    {
        $activeCutoff = now()->subMinutes(max(1, (int) config('session.lifetime', 120)))->timestamp;

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function ($session) use ($currentSessionId, $activeCutoff): array {
                [$browser, $platform] = $this->describeUserAgent((string) $session->user_agent);

                return [
                    'token' => ActiveSessionController::opaqueToken($session->id),
                    'isCurrent' => hash_equals($session->id, $currentSessionId),
                    'deviceLabel' => $browser.' sur '.$platform,
                    'browser' => $browser,
                    'platform' => $platform,
                    'ipAddress' => $this->approximateIp($session->ip_address),
                    'lastActiveAt' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                    'state' => (int) $session->last_activity >= $activeCutoff ? 'recent' : 'stale',
                ];
            })
            ->values();
    }

    /** @return array{string, string} */
    private function describeUserAgent(string $agent): array
    {
        $browser = str_contains($agent, 'Edg/') ? 'Edge'
            : (str_contains($agent, 'Firefox/') ? 'Firefox'
                : (str_contains($agent, 'Chrome/') ? 'Chrome'
                    : (str_contains($agent, 'Safari/') ? 'Safari' : 'Navigateur')));
        $platform = str_contains($agent, 'Windows') ? 'Windows'
            : (str_contains($agent, 'iPhone') ? 'iPhone'
                : (str_contains($agent, 'iPad') ? 'iPad'
                    : (str_contains($agent, 'Android') ? 'Android'
                        : (str_contains($agent, 'Mac OS') ? 'macOS'
                            : (str_contains($agent, 'Linux') ? 'Linux' : 'Appareil')))));

        return [$browser, $platform];
    }

    private function approximateIp(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = 'xxx';

            return implode('.', $parts);
        }

        $parts = explode(':', $ip);

        return implode(':', array_slice($parts, 0, 3)).':…';
    }
}
