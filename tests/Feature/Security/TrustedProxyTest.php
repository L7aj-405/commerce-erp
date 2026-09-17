<?php

namespace Tests\Feature\Security;

use App\Support\TrustedProxyRanges;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Sprint 1.1 §2 — trusted-proxy configuration. Exercises the actual
 * Symfony/Laravel trusted-proxy resolution logic directly against
 * TrustedProxyRanges' output (the same list bootstrap/app.php feeds
 * `trustProxies(at: ...)` at boot) rather than a live reverse proxy, which
 * this test suite has no business depending on.
 */
class TrustedProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        // Request::setTrustedProxies() is process-global static state —
        // never leak this test's configuration into another test.
        Request::setTrustedProxies([], -1);

        parent::tearDown();
    }

    public function test_default_ranges_are_private_only_never_a_wildcard(): void
    {
        putenv('TRUSTED_PROXIES');

        $ranges = TrustedProxyRanges::resolve();

        $this->assertSame(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'], $ranges);
    }

    public function test_an_untrusted_direct_connection_cannot_spoof_the_client_ip(): void
    {
        Request::setTrustedProxies(
            TrustedProxyRanges::resolve(),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // A public internet IP directly hitting the app — not Coolify's
        // internal Traefik hop — is never in the trusted private ranges, so
        // its X-Forwarded-For must be ignored entirely.
        $request = Request::create('/up', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->headers->set('X-Forwarded-For', '198.51.100.99');

        $this->assertSame('203.0.113.7', $request->getClientIp());
    }

    public function test_the_internal_proxy_hop_is_trusted_and_its_forwarded_header_is_honoured(): void
    {
        Request::setTrustedProxies(
            TrustedProxyRanges::resolve(),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Traefik's own container address, on Coolify's internal Docker
        // network, is within the trusted private ranges.
        $request = Request::create('/up', 'GET', server: ['REMOTE_ADDR' => '172.18.0.5']);
        $request->headers->set('X-Forwarded-For', '198.51.100.99');

        $this->assertSame('198.51.100.99', $request->getClientIp());
    }

    public function test_an_explicit_trusted_proxies_env_override_is_honoured(): void
    {
        putenv('TRUSTED_PROXIES=203.0.113.50');

        $this->assertSame(['203.0.113.50'], TrustedProxyRanges::resolve());

        putenv('TRUSTED_PROXIES');
    }
}
