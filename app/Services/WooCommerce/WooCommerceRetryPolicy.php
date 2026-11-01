<?php

namespace App\Services\WooCommerce;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pure retry decisions of the WooCommerce client.
 *
 *  - 429                → retry; wait Retry-After when sent (seconds or HTTP-date),
 *                         otherwise exponential backoff with jitter;
 *  - 500/502/503/504    → retry with bounded exponential backoff (Retry-After
 *                         honoured too when a 503 sends one);
 *  - connection failure → retry with exponential backoff;
 *  - anything else      → never retried (401/403 auth, 404, 400 validation…).
 */
final class WooCommerceRetryPolicy
{
    public const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(
        public readonly int $maxRetries,
        public readonly float $baseDelaySeconds,
        public readonly float $jitterRatio,
        public readonly float $maxDelaySeconds,
        public readonly float $maxTotalBackoffSeconds,
        public readonly float $minIntervalSeconds,
    ) {}

    public static function forSync(): self
    {
        return new self(
            maxRetries: max(0, (int) config('woocommerce.max_retries', 5)),
            baseDelaySeconds: max(0.0, (int) config('woocommerce.retry_base_delay_ms', 1000) / 1000),
            jitterRatio: min(1.0, max(0.0, (float) config('woocommerce.retry_jitter', 0.25))),
            maxDelaySeconds: max(1.0, (float) config('woocommerce.max_retry_delay_seconds', 120)),
            maxTotalBackoffSeconds: max(0.0, (float) config('woocommerce.max_total_backoff_seconds', 900)),
            minIntervalSeconds: max(0.0, (int) config('woocommerce.min_request_interval_ms', 250) / 1000),
        );
    }

    /** "Test connection": an interactive web request — at most one short retry. */
    public static function interactive(): self
    {
        $sync = self::forSync();

        return new self(
            maxRetries: max(0, (int) config('woocommerce.interactive_max_retries', 1)),
            baseDelaySeconds: $sync->baseDelaySeconds,
            jitterRatio: $sync->jitterRatio,
            maxDelaySeconds: max(0.0, (float) config('woocommerce.interactive_max_retry_delay_seconds', 3)),
            maxTotalBackoffSeconds: max(0.0, (float) config('woocommerce.interactive_max_retry_delay_seconds', 3)),
            minIntervalSeconds: 0.0,
        );
    }

    public function isRetryableStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Exponential backoff for retry number $attempt (1-based): base · 2^(n−1),
     * ± jitter, capped at the max delay. $jitter is a factor in [-1, 1].
     */
    public function backoff(int $attempt, float $jitter): float
    {
        $delay = $this->baseDelaySeconds * (2 ** max(0, $attempt - 1));
        $delay += $delay * $this->jitterRatio * max(-1.0, min(1.0, $jitter));

        return round(min($this->maxDelaySeconds, max(0.0, $delay)), 3);
    }

    /**
     * Seconds the server asked us to wait, from a Retry-After header in either
     * RFC 9110 form: delta-seconds ("5") or an HTTP-date. Null when absent or
     * unparseable; never negative.
     */
    public static function retryAfterSeconds(?string $header, float $now): ?float
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $header)) {
            return (float) $header;
        }

        try {
            $at = CarbonImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $header, 'UTC')
                ?: CarbonImmutable::parse($header);
        } catch (Throwable) {
            return null;
        }

        return max(0.0, round($at->getTimestamp() + $at->micro / 1_000_000 - $now, 3));
    }
}
