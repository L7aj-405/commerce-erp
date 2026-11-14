<?php

namespace App\Services\Sandbox;

use RuntimeException;

/** A Sandbox reset that was refused or rolled back; nothing was committed. */
final class SandboxResetException extends RuntimeException
{
    /** @param 'forbidden'|'blocked'|'stale'|'failed' $reason */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
