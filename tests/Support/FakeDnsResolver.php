<?php

namespace Tests\Support;

use App\Contracts\DnsResolver;

/**
 * Test double bound in place of {@see \App\Services\Security\SystemDnsResolver}
 * for every test (see Tests\TestCase::setUp()) so OutboundDestinationGuard
 * validation never performs a real DNS lookup. Every hostname resolves to a
 * safe, genuinely public documentation IP by default — existing WooCommerce /
 * SMTP fixtures (`shop.test`, `smtp.example.test`, …) keep working unchanged.
 * A test exercising the SSRF guard itself overrides specific hosts via
 * `map()`/`mapPrivate()`.
 */
class FakeDnsResolver implements DnsResolver
{
    /** A real, public (non-reserved) IP — used as the default "safe" answer. */
    public const DEFAULT_PUBLIC_IP = '93.184.216.34';

    /** @var array<string, list<string>> */
    private array $overrides = [];

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->overrides[strtolower($host)] ?? [self::DEFAULT_PUBLIC_IP];
    }

    /** @param list<string> $addresses */
    public function map(string $host, array $addresses): static
    {
        $this->overrides[strtolower($host)] = $addresses;

        return $this;
    }

    public function mapUnresolvable(string $host): static
    {
        return $this->map($host, []);
    }
}
