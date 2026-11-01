<?php

namespace Tests\Support;

use App\Services\WooCommerce\WooCommerceClock;

/**
 * Deterministic clock for the WooCommerce client: sleeping only advances the
 * fake time and is recorded, so backoff / pacing are asserted without waiting.
 */
class FakeWooCommerceClock extends WooCommerceClock
{
    /** @var list<float> every wait requested (pacing and backoff) */
    public array $sleeps = [];

    public float $jitterValue = 0.0;

    public function __construct(public float $time = 1_790_000_000.0) {}

    public function now(): float
    {
        return $this->time;
    }

    /** @var (callable(float): void)|null observe the system while "waiting" */
    public $onSleep = null;

    public function sleep(float $seconds): void
    {
        if ($this->onSleep) {
            ($this->onSleep)($seconds);
        }
        $this->sleeps[] = round($seconds, 3);
        $this->time += max(0, $seconds);
    }

    public function jitter(): float
    {
        return $this->jitterValue;
    }

    /** Waits longer than pacing: the backoff delays. @return list<float> */
    public function backoffs(float $pacingCeiling = 0.25): array
    {
        return array_values(array_filter($this->sleeps, fn (float $s) => $s > $pacingCeiling));
    }
}
