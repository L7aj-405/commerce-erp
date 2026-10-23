<?php

namespace App\Services\Mcp\OAuth;

use RuntimeException;

/**
 * Standard OAuth error (RFC 6749 §4.1.2.1 / §5.2, RFC 7591 §3.2.2). The
 * description is a fixed, sanitized sentence — never echoes client input,
 * tokens or internal state.
 */
final class McpOAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($error);
    }

    /** @return array{error: string, error_description: string} */
    public function payload(): array
    {
        return ['error' => $this->error, 'error_description' => $this->description];
    }
}
