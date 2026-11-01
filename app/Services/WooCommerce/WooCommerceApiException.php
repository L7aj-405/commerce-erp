<?php

namespace App\Services\WooCommerce;

use RuntimeException;

/**
 * A WooCommerce API failure translated into a safe, human-readable category.
 * Never carries credentials, raw response bodies, or stack context — only the
 * safe request diagnostics in `$context` (endpoint path, page, status, attempts,
 * Retry-After, rate-limit headers, duration).
 */
class WooCommerceApiException extends RuntimeException
{
    public const UNREACHABLE = 'unreachable';       // DNS / TLS / connection refused / timeout

    public const AUTH = 'auth';                     // 401 / 403 — bad consumer key or secret

    public const API_UNAVAILABLE = 'api_unavailable'; // 404 on wc/v3, WP without WooCommerce, 5xx

    public const TRANSIENT = 'transient';           // temporary failure (legacy category)

    public const RATE_LIMITED = 'rate_limited';     // 429 still refused after the controlled backoff

    public const INVALID_RESPONSE = 'invalid_response'; // non-JSON / unexpected shape

    public const SSRF_BLOCKED = 'ssrf_blocked'; // destination (or a redirect hop) resolves to a private/internal address

    /**
     * @param  array<string, mixed>  $context  safe diagnostics only
     */
    public function __construct(public readonly string $category, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        return new self($this->category, $this->getMessage(), [...$this->context, ...$context]);
    }

    public function userMessage(): string
    {
        return match ($this->category) {
            self::AUTH => 'Identifiants WooCommerce invalides.',
            self::UNREACHABLE => 'Boutique inaccessible.',
            self::API_UNAVAILABLE => 'WooCommerce REST API indisponible.',
            self::TRANSIENT => 'WooCommerce a temporairement refusé la requête. Réessayez plus tard.',
            self::RATE_LIMITED => $this->rateLimitedMessage(),
            self::INVALID_RESPONSE => 'Réponse WooCommerce inattendue.',
            self::SSRF_BLOCKED => 'Cette adresse de boutique n’est pas autorisée (destination interne ou privée).',
            default => 'La connexion à WooCommerce a échoué.',
        };
    }

    private function rateLimitedMessage(): string
    {
        $retryAfter = $this->context['retry_after_seconds'] ?? null;

        return 'Limite de requêtes WooCommerce atteinte (HTTP 429).'
            .($retryAfter !== null ? ' La boutique accepte de nouvelles requêtes dans '.(int) ceil((float) $retryAfter).' s.' : ' Réessayez dans quelques minutes.');
    }
}
