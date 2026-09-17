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

        $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

        if ($version !== null) {
            $this->withHeader('X-Inertia-Version', $version);
        }

        // Every OutboundDestinationGuard check (WooCommerce store URL, SMTP
        // host) resolves through this fake instead of a real DNS lookup —
        // see Tests\Support\FakeDnsResolver. Any test exercising the SSRF
        // guard itself calls fakeDns() to override specific hosts.
        $this->app->instance(DnsResolver::class, new Support\FakeDnsResolver);
    }

    protected function fakeDns(): Support\FakeDnsResolver
    {
        return $this->app->make(DnsResolver::class);
    }
}
