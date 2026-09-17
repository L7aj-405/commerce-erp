<?php

namespace App\Services\Security;

use App\Exceptions\Security\UnsafeOutboundDestinationException;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the SMTP transport for a TENANT-supplied mail host (organization
 * settings, see {@see \App\Services\OrganizationOutboundMailService}) —
 * never for the platform's own `mail.mailers.smtp` definition, which keeps
 * using Laravel's stock `MailManager::createSmtpTransport()` untouched and
 * may legitimately sit on an internal/private relay that this guard would
 * otherwise reject. A tenant's host is arbitrary, attacker-reachable input —
 * the same class of SSRF surface as the WooCommerce store URL — so it is
 * registered as its own `tenant_smtp` transport (see
 * `AppServiceProvider::boot()`'s `Mail::extend('tenant_smtp', ...)`) and gets
 * the SMTP-side equivalent of WooCommerceClient's `CURLOPT_RESOLVE` pinning:
 * resolve -> validate -> connect to the exact validated address, while the
 * original hostname is kept as the TLS `peer_name` so the certificate is
 * still verified against the real host, not the pinned IP. This closes the
 * same DNS-rebinding TOCTOU window HTTP has: DNS answering differently
 * between our check and the socket Symfony Mailer would otherwise re-resolve
 * and open itself.
 */
class TenantSmtpTransportFactory
{
    public function __construct(private readonly OutboundDestinationGuard $guard) {}

    /** @param array<string, mixed> $config */
    public function create(array $config): TransportInterface
    {
        $host = (string) $config['host'];

        try {
            $address = $this->guard->pinnedAddress($host, 'SMTP');
        } catch (UnsafeOutboundDestinationException $exception) {
            // Never a home-grown transport: fail closed before Symfony Mailer
            // ever attempts to connect anywhere.
            throw new RuntimeException("Unsafe SMTP destination ({$exception->category}).", previous: $exception);
        }

        $scheme = $config['scheme'] ?? (($config['port'] ?? null) == 465 ? 'smtps' : 'smtp');

        $transport = (new EsmtpTransportFactory)->create(new Dsn(
            $scheme,
            $host,
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['port'] ?? null,
            $config,
        ));

        if (! $transport instanceof SmtpTransport) {
            return $transport;
        }

        if (isset($config['source_ip'])) {
            $transport->getStream()->setSourceIp($config['source_ip']);
        }

        if (isset($config['timeout'])) {
            $transport->getStream()->setTimeout($config['timeout']);
        }

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setHost(str_contains($address, ':') ? "[{$address}]" : $address);
            $stream->setStreamOptions([
                'ssl' => [
                    'peer_name' => $host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
        }

        return $transport;
    }
}
