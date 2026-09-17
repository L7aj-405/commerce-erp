<?php

namespace Tests\Feature\Security;

use App\Services\Security\TenantSmtpTransportFactory;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * Sprint 1.1 §1 — SMTP-side equivalent of WooCommerceClient's CURLOPT_RESOLVE
 * pinning. No real socket is ever opened here (nothing calls ->send()); this
 * only asserts the transport is built with the connection pinned to the
 * DNS-validated address while the original hostname is kept for TLS
 * certificate verification (`ssl.peer_name`).
 */
class TenantSmtpTransportFactoryTest extends TestCase
{
    private function factory(): TenantSmtpTransportFactory
    {
        return $this->app->make(TenantSmtpTransportFactory::class);
    }

    public function test_it_pins_the_stream_to_the_validated_address_and_keeps_the_original_host_for_tls(): void
    {
        $this->fakeDns()->map('smtp.example.com', ['93.184.216.34']);

        $transport = $this->factory()->create([
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'user',
            'password' => 'secret',
            'encryption' => 'tls',
        ]);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('93.184.216.34', $stream->getHost());
        $this->assertSame('smtp.example.com', $stream->getStreamOptions()['ssl']['peer_name']);
        $this->assertTrue($stream->getStreamOptions()['ssl']['verify_peer']);
        $this->assertTrue($stream->getStreamOptions()['ssl']['verify_peer_name']);
    }

    public function test_it_brackets_an_ipv6_pinned_address(): void
    {
        $this->fakeDns()->map('smtp-v6.example.com', ['2606:2800:220:1::1']);

        $transport = $this->factory()->create([
            'host' => 'smtp-v6.example.com',
            'port' => 587,
        ]);

        $this->assertSame('[2606:2800:220:1::1]', $transport->getStream()->getHost());
    }

    public function test_it_rejects_a_host_that_resolves_privately(): void
    {
        $this->fakeDns()->map('internal-mail.corp.test', ['10.0.0.9']);

        $this->expectException(RuntimeException::class);

        $this->factory()->create(['host' => 'internal-mail.corp.test', 'port' => 587]);
    }

    public function test_it_rejects_localhost(): void
    {
        $this->expectException(RuntimeException::class);

        $this->factory()->create(['host' => 'localhost', 'port' => 587]);
    }
}
