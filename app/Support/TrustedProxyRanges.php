<?php

namespace App\Support;

/**
 * Which upstream IPs Laravel's TrustProxies middleware treats as a proxy —
 * i.e. whose `X-Forwarded-For`/`-Host`/`-Proto` headers it honours instead of
 * the raw TCP peer address (Sprint 1.1 §2).
 *
 * Coolify's deployment topology (see DEPLOYMENT_CHECKLIST.md §16) never
 * exposes this app container directly to the internet — Coolify's built-in
 * Traefik reverse proxy is the only internet-facing hop, and it always
 * connects to the app from Traefik's own container on Coolify's internal
 * Docker network, a private-range address. `trustProxies(at: '*')` (the
 * previous setting) went further than that topology needs: it told Laravel
 * to trust ANY REMOTE_ADDR as a legitimate proxy, so if the app container
 * were ever reached directly (a misconfiguration, a second exposed port, a
 * host-network escape), an internet client could set its own
 * `X-Forwarded-For` and have Laravel believe it — which is exactly what
 * `LoginThrottle` keys throttling on via `$request->ip()`.
 *
 * Trusting "any private-range address" instead of `'*'` keeps the dynamic,
 * not-statically-knowable container IP Traefik gets on every redeploy
 * working (an explicit fixed IP/CIDR list is impractical here — Coolify does
 * not expose one), while an internet attacker cannot spoof a private-range
 * REMOTE_ADDR (their real TCP source IP cannot be forged at the IP-routing
 * level the way a header value can).
 *
 * `TRUSTED_PROXIES` (comma-separated IPs/CIDRs) overrides this entirely for
 * a deployment that CAN give Laravel Traefik's exact address/subnet.
 */
class TrustedProxyRanges
{
    /** @var list<string> */
    private const PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    /** @return list<string> */
    public static function resolve(): array
    {
        $configured = trim((string) env('TRUSTED_PROXIES', ''));

        if ($configured === '') {
            return self::PRIVATE_RANGES;
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
