<?php

namespace App\Services\OrganizationBackups;

final class OrganizationBackupManifest
{
    public const FORMAT = 'commerce-erp-organization-backup';
    public const VERSION = 2;

    public const LEGACY_UNSIGNED_VERSION = 1;

    public static function supports(array $manifest): bool
    {
        return ($manifest['format'] ?? null) === self::FORMAT
            && (int) ($manifest['version'] ?? 0) === self::VERSION;
    }

    public static function isLegacyUnsigned(array $manifest): bool
    {
        return ($manifest['format'] ?? null) === self::FORMAT
            && (int) ($manifest['version'] ?? 0) === self::LEGACY_UNSIGNED_VERSION;
    }
}
