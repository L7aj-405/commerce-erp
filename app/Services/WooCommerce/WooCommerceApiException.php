<?php

namespace App\Services\WooCommerce;

use RuntimeException;

/**
 * A WooCommerce API failure translated into a safe, human-readable category.
 * Never carries credentials, raw response bodies, or stack context.
 */
class WooCommerceApiException extends RuntimeException
{
    public const UNREACHABLE = 'unreachable';       // DNS / TLS / connection refused / timeout

    public const AUTH = 'auth';                     // 401 / 403 — bad consumer key or secret

    public const API_UNAVAILABLE = 'api_unavailable'; // 404 on wc/v3, WP without WooCommerce, 5xx

    public const TRANSIENT = 'transient';           // 429 / temporary 5xx after retries

    public const INVALID_RESPONSE = 'invalid_response'; // non-JSON / unexpected shape

    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return match ($this->category) {
            self::AUTH => 'Identifiants WooCommerce invalides.',
            self::UNREACHABLE => 'Boutique inaccessible.',
            self::API_UNAVAILABLE => 'WooCommerce REST API indisponible.',
            self::TRANSIENT => 'WooCommerce a temporairement refusé la requête. Réessayez plus tard.',
            self::INVALID_RESPONSE => 'Réponse WooCommerce inattendue.',
            default => 'La connexion à WooCommerce a échoué.',
        };
    }
}
