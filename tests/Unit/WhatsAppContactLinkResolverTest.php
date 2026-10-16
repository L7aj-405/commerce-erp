<?php

namespace Tests\Unit;

use App\Services\WhatsAppContactLinkResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppContactLinkResolverTest extends TestCase
{
    public function test_username_is_preferred_and_message_is_encoded(): void
    {
        $result = (new WhatsAppContactLinkResolver)->resolve('@client.test', '0600000000', 'Bonjour client');

        $this->assertTrue($result['available']);
        $this->assertSame('username', $result['type']);
        $this->assertSame('client.test', $result['destination']);
        $this->assertSame('https://wa.me/client.test', $result['base_url']);
        $this->assertSame('https://wa.me/client.test?text=Bonjour%20client', $result['link']);
    }

    public function test_phone_fallback_preserves_existing_normalization(): void
    {
        $result = (new WhatsAppContactLinkResolver)->resolve(null, '06 00 00 00 00');

        $this->assertSame('phone', $result['type']);
        $this->assertSame('212600000000', $result['destination']);
        $this->assertSame('https://wa.me/212600000000', $result['link']);
    }

    public function test_no_destination_is_unavailable(): void
    {
        $this->assertSame([
            'available' => false, 'type' => null, 'destination' => null,
            'base_url' => null, 'link' => null,
        ], (new WhatsAppContactLinkResolver)->resolve(null, null));
    }

    #[DataProvider('invalidUsernames')]
    public function test_invalid_username_falls_back_to_phone(string $username): void
    {
        $result = (new WhatsAppContactLinkResolver)->resolve($username, '0600000000');

        $this->assertSame('phone', $result['type']);
    }

    public static function invalidUsernames(): array
    {
        return [
            'space' => ['client test'],
            'control' => ["client\ntest"],
            'url' => ['https://wa.me/client.test'],
            'numeric only' => ['123456'],
        ];
    }
}
