<?php

namespace App\Services\OrganizationDataReset;

/**
 * An unexpected failure during an otherwise authorized reset. The whole
 * transaction was rolled back; the cause is logged server-side under
 * `$reference`, which the user can quote (never the raw SQL / message).
 */
class OrganizationDataResetFailedException extends OrganizationDataResetException
{
    public function __construct(public readonly string $reference, ?\Throwable $previous = null)
    {
        parent::__construct("Organization data reset failed and was rolled back [{$reference}].", 0, $previous);
    }
}
