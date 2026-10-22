<?php

namespace App\Services\OrganizationDataReset;

final class OrganizationDataResetConfirmation
{
    public const PHRASE = 'RESET ORGANIZATION DATA';

    public static function matches(string $value): bool
    {
        return hash_equals(self::PHRASE, $value);
    }
}
