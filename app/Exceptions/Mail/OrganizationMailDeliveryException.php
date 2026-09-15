<?php

namespace App\Exceptions\Mail;

use RuntimeException;
use Throwable;

/**
 * Thrown when a real SMTP send attempt fails (auth rejected, host unreachable,
 * timeout, …). The message is always the safe, generic user-facing string —
 * never the raw transport exception, which can echo back connection details.
 * The technical cause stays available via getPrevious() for server-side
 * logging only; callers must not log SMTP credentials alongside it.
 */
class OrganizationMailDeliveryException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('L\'e-mail n\'a pas pu être envoyé.', previous: $previous);
    }
}
