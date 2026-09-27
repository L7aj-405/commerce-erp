<?php

namespace App\Services\OrganizationBackups;

use RuntimeException;

class OrganizationBackupException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
