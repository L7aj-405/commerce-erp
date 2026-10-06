<?php

namespace App\Services\Security;

use App\Models\TrustedTwoFactorDevice;
use App\Models\User;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class TrustedTwoFactorDeviceManager
{
    public function __construct(private readonly CookieJar $cookies) {}

    /** @return list<int> */
    public function allowedDurations(): array
    {
        $maximum = max(1, (int) config('two-factor.trusted_device_max_days', 30));

        return collect(config('two-factor.trusted_device_durations', [7, 15, 20, 30]))
            ->map(fn ($days) => (int) $days)->filter(fn (int $days) => $days > 0 && $days <= $maximum)
            ->unique()->sort()->values()->all();
    }

    public function defaultDuration(): int
    {
        $allowed = $this->allowedDurations();
        $configured = (int) config('two-factor.trusted_device_default_days', 30);

        return in_array($configured, $allowed, true) ? $configured : ($allowed[0] ?? 7);
    }

    public function validFor(User $user, Request $request): ?TrustedTwoFactorDevice
    {
        [$id, $token] = $this->cookieParts($request->cookie($this->cookieName()));
        if (! $id || ! $token) {
            return null;
        }

        $device = TrustedTwoFactorDevice::query()->whereKey($id)->where('user_id', $user->getKey())->first();
        if (! $device || ! $device->isUsable() || ! hash_equals($device->token_hash, hash('sha256', $token))) {
            return null;
        }

        $device->last_used_at = now();
        $device->last_ip = $request->ip();
        $device->save();

        return $device;
    }

    /** @return array{TrustedTwoFactorDevice, Cookie} */
    public function create(User $user, Request $request, int $durationDays): array
    {
        abort_unless(in_array($durationDays, $this->allowedDurations(), true), 422, 'Durée de confiance invalide.');
        $token = bin2hex(random_bytes(32));
        [$browser, $platform] = $this->describeUserAgent((string) $request->userAgent());

        $device = new TrustedTwoFactorDevice;
        $device->user_id = $user->getKey();
        $device->token_hash = hash('sha256', $token);
        $device->browser = $browser;
        $device->platform = $platform;
        $device->device_name = trim($browser.' sur '.$platform);
        $device->created_ip = $request->ip();
        $device->last_ip = $request->ip();
        $device->last_used_at = now();
        $device->expires_at = now()->addDays($durationDays);
        $device->save();

        return [$device, $this->makeCookie($device->id.'|'.$token, $durationDays * 1440)];
    }

    public function revokeAll(User $user): int
    {
        return TrustedTwoFactorDevice::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function forgetCookie(): Cookie
    {
        return $this->cookies->forget($this->cookieName(), (string) config('session.path', '/'), config('session.domain'));
    }

    public function currentDeviceId(Request $request): ?string
    {
        return $this->cookieParts($request->cookie($this->cookieName()))[0];
    }

    private function makeCookie(string $value, int $minutes): Cookie
    {
        $secure = app()->environment('production') || (bool) config('session.secure');

        return $this->cookies->make(
            $this->cookieName(),
            $value,
            $minutes,
            (string) config('session.path', '/'),
            config('session.domain'),
            $secure,
            true,
            false,
            (string) config('two-factor.trusted_device_same_site', 'lax'),
        );
    }

    /** @return array{?string, ?string} */
    private function cookieParts(?string $value): array
    {
        if (! $value || ! str_contains($value, '|')) {
            return [null, null];
        }

        [$id, $token] = explode('|', $value, 2);
        $valid = Str::isUuid($id) && preg_match('/^[a-f0-9]{64}$/', $token) === 1;

        return $valid ? [$id, $token] : [null, null];
    }

    /** @return array{string, string} */
    private function describeUserAgent(string $agent): array
    {
        $browser = str_contains($agent, 'Edg/') ? 'Edge' : (str_contains($agent, 'Firefox/') ? 'Firefox' : (str_contains($agent, 'Chrome/') ? 'Chrome' : (str_contains($agent, 'Safari/') ? 'Safari' : 'Navigateur')));
        $platform = str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'iPhone') ? 'iPhone' : (str_contains($agent, 'iPad') ? 'iPad' : (str_contains($agent, 'Android') ? 'Android' : (str_contains($agent, 'Mac OS') ? 'macOS' : (str_contains($agent, 'Linux') ? 'Linux' : 'Appareil')))));

        return [$browser, $platform];
    }

    private function cookieName(): string
    {
        return (string) config('two-factor.trusted_device_cookie', 'trusted_2fa_device');
    }
}
