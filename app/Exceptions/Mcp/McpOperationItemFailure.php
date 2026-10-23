<?php

namespace App\Exceptions\Mcp;

use RuntimeException;

/** A permanent, non-retryable per-item failure carrying a sanitized reason code. */
final class McpOperationItemFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
