<?php

namespace App\Contracts;

/**
 * Resolves a hostname to its IP addresses. Extracted behind an interface so
 * {@see \App\Services\Security\OutboundDestinationGuard} can be unit-tested
 * without performing real DNS lookups (production traffic against a `.test`/
 * `.example` fixture host would otherwise either hang or — worse — silently
 * fail closed and break every existing WooCommerce/SMTP test).
 */
interface DnsResolver
{
    /**
     * All A/AAAA addresses for $host, or a literal single-element list when
     * $host is already an IP address. Returns an empty array when the host
     * cannot be resolved at all.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
