<?php

namespace Tests;

use App\Contracts\DnsResolver;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PHP tests must not depend on the frontend build (public/build manifest) or a running Vite dev
        // server (public/hot): without this, every page render returns a 500 on a fresh checkout / CI
        // runner and "passes" only on a machine that happens to have built assets or a hot file.
        $this->withoutVite();

        $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

        if ($version !== null) {
            $this->withHeader('X-Inertia-Version', $version);
        }

        // Every OutboundDestinationGuard check (WooCommerce store URL, SMTP
        // host) resolves through this fake instead of a real DNS lookup —
        // see Tests\Support\FakeDnsResolver. Any test exercising the SSRF
        // guard itself calls fakeDns() to override specific hosts.
        $this->app->instance(DnsResolver::class, new Support\FakeDnsResolver);

        // Dedicated deterministic test-only backup keys. Production must
        // supply independent random values through the environment.
        config([
            'organization-backups.signing_key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'organization-backups.encryption_key' => 'base64:'.base64_encode(str_repeat('e', 32)),
        ]);
    }

    protected function fakeDns(): Support\FakeDnsResolver
    {
        return $this->app->make(DnsResolver::class);
    }
}
