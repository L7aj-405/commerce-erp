<?php

namespace App\Services\WooCommerce;

/**
 * Time source + sleeper of the WooCommerce client (pacing and backoff). A
 * container binding, so tests swap in a fake that records waits instead of
 * sleeping. Only ever called outside database transactions: the client is
 * used between per-product transactions, never inside one.
 */
class WooCommerceClock
{
    /** Monotonic-enough wall time in seconds. */
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /** Random factor in [-1, 1] for jitter. */
    public function jitter(): float
    {
        return (mt_rand() / mt_getrandmax()) * 2 - 1;
    }
}
