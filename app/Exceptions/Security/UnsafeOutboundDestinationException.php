<?php

namespace App\Exceptions\Security;

use RuntimeException;

/**
 * Raised by {@see \App\Services\Security\OutboundDestinationGuard} when a
 * tenant-controlled destination (WooCommerce store URL, SMTP host, a redirect
 * target) is malformed or resolves to a private/reserved/internal address.
 *
 * `userMessage()` is intentionally generic — it never echoes the resolved IP,
 * the private range it fell in, or any other detail an attacker could use to
 * map internal infrastructure by observing error text. Sanitized diagnostic
 * context for administrators/logs lives in `$context`, not in the message.
 */
class UnsafeOutboundDestinationException extends RuntimeException
{
    public const MALFORMED = 'malformed';

    public const UNRESOLVABLE = 'unresolvable';

    public const PRIVATE_DESTINATION = 'private_destination';

    /** @param array<string, mixed> $context sanitized (never IP/host-free) diagnostic detail for logs only */
    public function __construct(public readonly string $category, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function malformed(string $subject): self
    {
        return new self(self::MALFORMED, "Invalid {$subject} URL/host.", ['subject' => $subject]);
    }

    public static function unresolvable(string $subject): self
    {
        return new self(self::UNRESOLVABLE, "The {$subject} host could not be resolved.", ['subject' => $subject]);
    }

    public static function private(string $subject): self
    {
        return new self(self::PRIVATE_DESTINATION, "The {$subject} destination is not a public host.", ['subject' => $subject]);
    }

    public function userMessage(): string
    {
        return match ($this->category) {
            self::MALFORMED => 'Cette adresse n’est pas valide.',
            self::UNRESOLVABLE => 'Impossible de résoudre le nom de domaine de cette adresse.',
            self::PRIVATE_DESTINATION => 'Cette adresse pointe vers une destination interne ou privée, ce qui n’est pas autorisé.',
            default => 'Cette destination n’est pas autorisée.',
        };
    }
}
