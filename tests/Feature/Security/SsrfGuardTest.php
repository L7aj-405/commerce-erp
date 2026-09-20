<?php

namespace Tests\Feature\Security;

use App\Contracts\DnsResolver;
use App\Exceptions\Security\UnsafeOutboundDestinationException;
use App\Services\Security\OutboundDestinationGuard;
use Tests\TestCase;

/**
 * Unit-level coverage of the OutboundDestinationGuard itself. HTTP-level
 * coverage (WooCommerce save/sync, SMTP save/send) lives in
 * WooCommerceSsrfTest and OrganizationMailSettingSsrfTest — this file only
 * exercises the guard's own IP/DNS/URL logic, independent of any controller.
 */
class SsrfGuardTest extends TestCase
{
    private function guard(): OutboundDestinationGuard
    {
        return $this->app->make(OutboundDestinationGuard::class);
    }

    public function test_a_genuinely_public_host_is_allowed(): void
    {
        $this->fakeDns()->map('shop.example.com', ['93.184.216.34']);

        $this->guard()->assertPublicHost('shop.example.com', 'test');
        $this->assertTrue(true); // no exception thrown
    }

    public function test_a_public_ipv4_with_its_dns64_nat64_answer_is_allowed(): void
    {
        $this->fakeDns()->map('dual-stack.example.com', [
            '198.177.120.96',
            '64:ff9b::c6b1:7860',
        ]);

        $this->guard()->assertPublicHost('dual-stack.example.com', 'test');
        $this->assertTrue(true);
    }

    public function test_localhost_by_name_is_rejected_without_even_resolving(): void
    {
        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('localhost', 'test');
    }

    public function test_a_subdomain_of_localhost_is_rejected(): void
    {
        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('admin.localhost', 'test');
    }

    public function test_literal_127_0_0_1_is_rejected(): void
    {
        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('127.0.0.1', 'test');
    }

    public function test_a_hostname_resolving_to_an_rfc1918_address_is_rejected(): void
    {
        $this->fakeDns()->map('internal.corp.test', ['10.0.0.5']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('internal.corp.test', 'test');
    }

    public function test_a_hostname_resolving_to_a_link_local_metadata_address_is_rejected(): void
    {
        $this->fakeDns()->map('metadata.evil.test', ['169.254.169.254']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('metadata.evil.test', 'test');
    }

    public function test_a_hostname_with_a_mixed_public_and_private_dns_answer_is_rejected(): void
    {
        // Only one of the two resolved addresses needs to be private to fail
        // the whole destination — the attacker only needs the request to ever
        // land on the private one.
        $this->fakeDns()->map('mixed.evil.test', ['93.184.216.34', '192.168.1.1']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('mixed.evil.test', 'test');
    }

    public function test_an_unresolvable_hostname_is_rejected(): void
    {
        $this->fakeDns()->mapUnresolvable('nxdomain.evil.test');

        try {
            $this->guard()->assertPublicHost('nxdomain.evil.test', 'test');
            $this->fail('Expected an unresolvable destination exception.');
        } catch (UnsafeOutboundDestinationException $exception) {
            $this->assertSame(UnsafeOutboundDestinationException::UNRESOLVABLE, $exception->category);
            $this->assertSame('Impossible de résoudre le nom de domaine de cette adresse.', $exception->userMessage());
        }
    }

    public function test_ipv6_loopback_is_rejected(): void
    {
        $this->fakeDns()->map('v6.evil.test', ['::1']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('v6.evil.test', 'test');
    }

    public function test_ipv6_unique_local_range_is_rejected(): void
    {
        $this->fakeDns()->map('v6-ula.evil.test', ['fd12:3456:789a::1']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('v6-ula.evil.test', 'test');
    }

    public function test_ipv6_link_local_range_is_rejected(): void
    {
        $this->fakeDns()->map('v6-link-local.evil.test', ['fe80::1']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('v6-link-local.evil.test', 'test');
    }

    public function test_nat64_wrapping_a_private_ipv4_address_is_rejected(): void
    {
        $this->fakeDns()->map('nat64-private.evil.test', ['64:ff9b::a00:5']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('nat64-private.evil.test', 'test');
    }

    public function test_ipv4_mapped_ipv6_wrapping_a_private_address_is_rejected(): void
    {
        $this->fakeDns()->map('v4mapped.evil.test', ['::ffff:10.1.1.1']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('v4mapped.evil.test', 'test');
    }

    public function test_cgnat_range_is_rejected(): void
    {
        $this->fakeDns()->map('cgnat.evil.test', ['100.64.0.1']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicHost('cgnat.evil.test', 'test');
    }

    public function test_a_url_with_embedded_credentials_is_rejected(): void
    {
        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicUrl('https://user:pass@shop.example.com/', ['https'], 'test');
    }

    public function test_a_disallowed_scheme_is_rejected(): void
    {
        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicUrl('http://shop.example.com/', ['https'], 'test');
    }

    public function test_a_valid_public_https_url_is_accepted(): void
    {
        $this->fakeDns()->map('shop.example.com', ['93.184.216.34']);

        $this->guard()->assertPublicUrl('https://shop.example.com/wp-json/wc/v3', ['https'], 'test');
        $this->assertTrue(true);
    }

    public function test_hostname_case_and_a_trailing_root_dot_are_normalized_for_resolution(): void
    {
        $this->fakeDns()->map('shop.example.com', ['93.184.216.34']);

        $this->guard()->assertPublicUrl('https://Shop.Example.Com./wp-json/wc/v3', ['https'], 'test');
        $this->assertTrue(true);
    }

    public function test_the_error_message_never_contains_the_resolved_ip(): void
    {
        $this->fakeDns()->map('internal.corp.test', ['10.0.0.5']);

        try {
            $this->guard()->assertPublicHost('internal.corp.test', 'test');
            $this->fail('Expected UnsafeOutboundDestinationException.');
        } catch (UnsafeOutboundDestinationException $exception) {
            $this->assertStringNotContainsString('10.0.0.5', $exception->userMessage());
            $this->assertStringNotContainsString('10.0.0.5', $exception->getMessage());
        }
    }

    public function test_the_bound_resolver_is_the_fake_in_tests_never_the_real_system_resolver(): void
    {
        $this->assertInstanceOf(\Tests\Support\FakeDnsResolver::class, $this->app->make(DnsResolver::class));
    }

    /*
     |-------------------------------------------------------------------
     | Connection-level pinning (Sprint 1.1 §1 — DNS-rebinding closure)
     |-------------------------------------------------------------------
     */

    public function test_pinned_address_returns_the_validated_ip_for_a_public_host(): void
    {
        $this->fakeDns()->map('shop.example.com', ['93.184.216.34']);

        $this->assertSame('93.184.216.34', $this->guard()->pinnedAddress('shop.example.com', 'test'));
    }

    public function test_pinned_address_prefers_ipv4_when_both_families_are_present(): void
    {
        $this->fakeDns()->map('dual-stack.example.com', ['2606:2800:220:1::1', '93.184.216.34']);

        $this->assertSame('93.184.216.34', $this->guard()->pinnedAddress('dual-stack.example.com', 'test'));
    }

    public function test_pinned_address_rejects_a_host_that_resolves_privately(): void
    {
        $this->fakeDns()->map('internal.corp.test', ['10.0.0.5']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->pinnedAddress('internal.corp.test', 'test');
    }

    public function test_assert_public_url_pin_returns_host_port_and_validated_address(): void
    {
        $this->fakeDns()->map('shop.example.com', ['93.184.216.34']);

        $pin = $this->guard()->assertPublicUrlPin('https://shop.example.com/wp-json/wc/v3', ['https'], 'test');

        $this->assertSame('shop.example.com', $pin['host']);
        $this->assertSame(443, $pin['port']);
        $this->assertSame('93.184.216.34', $pin['address']);
    }

    public function test_assert_public_url_pin_honours_an_explicit_port(): void
    {
        $this->fakeDns()->map('shop.example.com', ['93.184.216.34']);

        $pin = $this->guard()->assertPublicUrlPin('https://shop.example.com:8443/wp-json', ['https'], 'test');

        $this->assertSame(8443, $pin['port']);
    }

    public function test_assert_public_url_pin_still_rejects_a_url_that_now_resolves_privately(): void
    {
        // The exact DNS-rebinding scenario this pin exists to close: DNS
        // answers differently between an earlier save-time check and this
        // request-time one — the pin computation itself must fail closed.
        $this->fakeDns()->map('shop.example.com', ['127.0.0.1']);

        $this->expectException(UnsafeOutboundDestinationException::class);
        $this->guard()->assertPublicUrlPin('https://shop.example.com/wp-json', ['https'], 'test');
    }
}
