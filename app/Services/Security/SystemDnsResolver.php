<?php

namespace App\Services\Security;

use App\Contracts\DnsResolver;

/**
 * Real DNS resolution used in production. IPv4 goes through
 * `gethostbynamel()`, which — unlike a raw DNS query — also consults the
 * system resolver stack (e.g. /etc/hosts), so "localhost" resolves the same
 * way it would for any other outbound connection this process makes. IPv6 has
 * no equivalent hosts()-aware primitive in PHP, so AAAA records are queried
 * directly.
 */
class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        $dnsRecords = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($dnsRecords)) {
            foreach ($dnsRecords as $record) {
                if (! empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (! empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
