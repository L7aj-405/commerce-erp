<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Observed release identity of THIS process (web or queue worker).
 *
 * Values come only from what the deployment injected into the process (APP_COMMIT_SHA, or the
 * platform variable SOURCE_COMMIT that Coolify provides) — never from source code and never from an
 * "expected" value. Anything absent or malformed is reported as null so callers show "Unverified".
 */
final class ReleaseIdentity
{
    /** A git object name: 7–40 hex characters. */
    public static function normalizeCommit(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{7,40}$/', $value) === 1 ? $value : null;
    }

    public static function normalizeTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Validated commit of this process, or null when unconfigured / malformed. */
    public static function commit(): ?string
    {
        return self::normalizeCommit(config('system-health.commit_sha'));
    }

    /** Where the commit comes from, for honest labelling: "env", "platform" or null. */
    public static function commitSource(): ?string
    {
        return self::commit() === null ? null : (string) config('system-health.commit_source');
    }

    /**
     * Two commits are the same release when both are valid and one is a prefix of the other
     * (a short SHA from one process vs the full SHA from another). null when not comparable.
     */
    public static function matches(?string $a, ?string $b): ?bool
    {
        $a = self::normalizeCommit($a);
        $b = self::normalizeCommit($b);
        if ($a === null || $b === null) {
            return null;
        }

        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }
}
