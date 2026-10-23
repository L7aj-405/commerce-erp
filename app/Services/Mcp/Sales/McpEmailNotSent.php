<?php

namespace App\Services\Mcp\Sales;

use RuntimeException;

/** A recorded (possibly replayed) send outcome that did not send the email. */
final class McpEmailNotSent extends RuntimeException
{
    /** @param array<string, mixed> $result */
    public function __construct(public readonly array $result)
    {
        parent::__construct('email_not_sent');
    }
}
