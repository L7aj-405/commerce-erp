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

    /**
     * Real process environment variable, read at call time (never from the config cache).
     * `config()` is frozen when `config:cache` ran (at build or container start), so a variable
     * the platform injects into the RUNNING process (e.g. Coolify's SOURCE_COMMIT in the worker)
     * is invisible to it. Real env vars do not depend on the .env file being loaded.
     */
    public static function runtimeEnv(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Resolution order for THIS process: live APP_COMMIT_SHA, live SOURCE_COMMIT, then the
     * (possibly cached) config value. Only a well-formed SHA is ever returned.
     *
     * @return array{0: string, 1: string}|null [sha, source label]
     */
    private static function resolveCommit(): ?array
    {
        foreach (['APP_COMMIT_SHA', 'SOURCE_COMMIT'] as $name) {
            $sha = self::normalizeCommit(self::runtimeEnv($name));
            if ($sha !== null) {
                return [$sha, $name.' (runtime env)'];
            }
        }
        $sha = self::normalizeCommit(config('system-health.commit_sha'));

        return $sha === null ? null : [$sha, ((string) config('system-health.commit_source')) ?: 'config'];
    }

    /** Validated commit of this process, or null when unconfigured / malformed. */
    public static function commit(): ?string
    {
        return self::resolveCommit()[0] ?? null;
    }

    /** Which variable supplied the commit, for honest labelling; null when unverified. */
    public static function commitSource(): ?string
    {
        return self::resolveCommit()[1] ?? null;
    }

    /** Declared build time of this process (live APP_BUILD_AT, then config), ISO-8601 or null. */
    public static function buildAt(): ?string
    {
        return self::normalizeTimestamp(self::runtimeEnv('APP_BUILD_AT')) ?? self::normalizeTimestamp(config('system-health.build_at'));
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
