<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * §G — centralized production HTTP security headers. Scoped to the
 * `production` environment only (see SecurityHeaders' class doc for why:
 * Vite's local dev server would violate this CSP) — this test switches the
 * environment for the duration of one request rather than asserting the
 * headers are present in the `testing` environment tests actually run in.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_present_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotNull($response->headers->get('Permissions-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_the_csp_allows_self_scripts_and_styles_only_no_third_party_script_host(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_security_headers_are_not_forced_outside_production(): void
    {
        // The default test environment ('testing') must not get the strict
        // CSP — it would break nothing here since assets aren't loaded, but
        // this pins the intended scoping documented on SecurityHeaders.
        $response = $this->get('/login');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }
}
