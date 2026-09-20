<?php

namespace App\Services\Security;

use App\Contracts\DnsResolver;
use App\Exceptions\Security\UnsafeOutboundDestinationException;

/**
 * Centralized SSRF guard for every outbound destination a tenant controls:
 * the WooCommerce store URL ({@see \App\Services\WooCommerce\WooCommerceClient})
 * and the organization's SMTP host ({@see \App\Services\OrganizationOutboundMailService}).
 * Never validate a tenant-supplied host/URL independently elsewhere — route it
 * through this class so both surfaces share one audited implementation.
 *
 * Validation resolves the hostname and rejects the destination if ANY
 * resolved address is private/reserved/internal (loopback, RFC1918,
 * link-local, CGNAT, documentation/benchmarking ranges, multicast, IPv4-mapped
 * IPv6 wrapping a private address, etc.) — a syntactically public hostname is
 * never sufficient on its own (DNS-based SSRF).
 *
 * Connection-level pinning (closes DNS rebinding, Sprint 1.1 §1): validating
 * a hostname and then letting the HTTP/SMTP client re-resolve it itself still
 * leaves a TOCTOU window — DNS can answer differently (a very short TTL,
 * timed precisely) between this check and the socket the client actually
 * opens. {@see pinnedAddress()} closes that window: it resolves + validates
 * exactly like {@see assertPublicHost()}, then returns ONE of the validated
 * addresses for the caller to connect to directly (WooCommerceClient via
 * `CURLOPT_RESOLVE`, {@see \App\Services\Security\TenantSmtpTransportFactory}
 * via the SMTP stream's host override) while the original hostname stays in
 * the request/DSN for TLS SNI, certificate verification, and the HTTP Host
 * header — never replaced by an IP at that level. Every candidate address
 * `pinnedAddress()` can return has already passed the exact same
 * public/private check as `assertPublicHost()`, so picking any one of them is
 * safe; the selection itself is deterministic only for stability, not safety.
 */
class OutboundDestinationGuard
{
    /** @var list<string> */
    private const IPV4_BLOCKED_CIDRS = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // RFC1918 private
        '100.64.0.0/10',    // CGNAT (RFC6598)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local (incl. cloud metadata endpoints)
        '172.16.0.0/12',    // RFC1918 private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1 (documentation)
        '192.168.0.0/16',   // RFC1918 private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2 (documentation)
        '203.0.113.0/24',   // TEST-NET-3 (documentation)
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved / future use
    ];

    /** @var list<string> */
    private const IPV6_BLOCKED_CIDRS = [
        '::/128',           // unspecified
        '::1/128',          // loopback
        '100::/64',         // discard-only
        'fc00::/7',         // unique local (private)
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    ];

    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * @param  list<string>  $allowedSchemes
     *
     * @throws UnsafeOutboundDestinationException
     */
    public function assertPublicUrl(string $url, array $allowedSchemes, string $subject): void
    {
        $host = $this->parseValidatedHost($url, $allowedSchemes, $subject)['host'];

        $this->assertPublicHost($host, $subject);
    }

    /**
     * @throws UnsafeOutboundDestinationException
     */
    public function assertPublicHost(string $host, string $subject): void
    {
        $this->publicAddressesFor($host, $subject);
    }

    /**
     * Validates $url exactly like {@see assertPublicUrl()}, then returns the
     * connection-level pin for the request the caller is about to make: the
     * host and port a client would use, plus ONE validated public address to
     * pin the actual socket to (see the class doc). The URL itself is left
     * untouched by the caller — only the low-level connect target changes.
     *
     * @param  list<string>  $allowedSchemes
     * @return array{host: string, port: int, address: string}
     *
     * @throws UnsafeOutboundDestinationException
     */
    public function assertPublicUrlPin(string $url, array $allowedSchemes, string $subject): array
    {
        $parsed = $this->parseValidatedHost($url, $allowedSchemes, $subject);

        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'],
            'address' => $this->pinnedAddress($parsed['host'], $subject),
        ];
    }

    /**
     * Resolves + validates $host exactly like {@see assertPublicHost()}, then
     * returns one of the validated public addresses to connect to directly.
     * Prefers an IPv4 answer when both families are present, purely for
     * determinism — every candidate has already passed the identical
     * public/private check, so any of them is safe to pin to.
     *
     * @throws UnsafeOutboundDestinationException
     */
    public function pinnedAddress(string $host, string $subject): string
    {
        $addresses = $this->publicAddressesFor($host, $subject);

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    /**
     * @param  list<string>  $allowedSchemes
     * @return array{host: string, port: int}
     *
     * @throws UnsafeOutboundDestinationException
     */
    private function parseValidatedHost(string $url, array $allowedSchemes, string $subject): array
    {
        $parts = parse_url(trim($url));

        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw UnsafeOutboundDestinationException::malformed($subject);
        }

        // Embedded credentials (https://user:pass@host/) are never legitimate
        // for these integrations and are a classic URL-parser confusion vector.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeOutboundDestinationException::malformed($subject);
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, array_map('strtolower', $allowedSchemes), true)) {
            throw UnsafeOutboundDestinationException::malformed($subject);
        }

        return [
            'host' => $this->stripBrackets($parts['host']),
            'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80),
        ];
    }

    /** @return list<string> validated public addresses for $host, in resolver order */
    private function publicAddressesFor(string $host, string $subject): array
    {
        $host = $this->stripBrackets(trim($host, " \t\n\r\0\x0B."));

        if ($host === '' || $this->isObviousLoopbackName($host)) {
            throw UnsafeOutboundDestinationException::private($subject);
        }

        $addresses = $this->dns->resolve($host);

        if ($addresses === []) {
            throw UnsafeOutboundDestinationException::unresolvable($subject);
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw UnsafeOutboundDestinationException::private($subject);
            }
        }

        return $addresses;
    }

    // parse_url() keeps the brackets around an IPv6 literal host (e.g.
    // "[fe80::1]") — strip them so the literal-IP fast path (and the real
    // resolver's own filter_var check) actually recognizes it, instead of
    // treating a bracketed IPv6 literal as an opaque hostname.
    private function stripBrackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    public function isPublicHost(string $host): bool
    {
        try {
            $this->assertPublicHost($host, 'host');

            return true;
        } catch (UnsafeOutboundDestinationException) {
            return false;
        }
    }

    private function isObviousLoopbackName(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === 'localhost.localdomain'
            || str_ends_with($host, '.localdomain')
            || $host === 'ip6-localhost'
            || $host === 'broadcasthost';
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        // IPv4-mapped IPv6 (::ffff:10.0.0.1) and the NAT64 well-known prefix
        // both embed a real IPv4 address in the low 32 bits. Validate that
        // embedded address instead of rejecting NAT64 wholesale: DNS64
        // resolvers legitimately synthesize 64:ff9b::/96 answers for public
        // IPv4 destinations. A private/reserved embedded address still fails.
        if ($isIpv6) {
            $embeddedIpv4 = $this->embeddedIpv4($ip);
            if ($embeddedIpv4 !== null) {
                return $this->isPublicIp($embeddedIpv4);
            }
        }

        // PHP's own, well-tested private/reserved-range detection covers the
        // common RFC1918 / loopback / link-local / unique-local cases for both
        // families; the explicit CIDR lists above close the gaps it leaves
        // open (CGNAT, documentation/benchmarking ranges, multicast, etc.).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach ($isIpv6 ? self::IPV6_BLOCKED_CIDRS : self::IPV4_BLOCKED_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function embeddedIpv4(string $ipv6): ?string
    {
        $packed = @inet_pton($ipv6);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        // ::ffff:0:0/96 (IPv4-mapped) and 64:ff9b::/96 (NAT64) both carry the
        // IPv4 address in the last 4 bytes; the constant part differs.
        $prefix = substr($packed, 0, 12);
        $ipv4MappedPrefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
        $nat64Prefix = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";

        if ($prefix !== $ipv4MappedPrefix && $prefix !== $nat64Prefix) {
            return null;
        }

        return inet_ntop(substr($packed, 12, 4)) ?: null;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);

        return (substr($ipBinary, $fullBytes, 1) & $mask) === (substr($subnetBinary, $fullBytes, 1) & $mask);
    }
}
