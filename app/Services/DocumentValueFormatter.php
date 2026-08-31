<?php

namespace App\Services;

use Carbon\CarbonInterface;

class DocumentValueFormatter
{
    public function money(string|int|null $value): string
    {
        return $this->number((string) ($value ?? '0'), 2, true);
    }

    public function decimal(string|int|null $value): string
    {
        return $this->number((string) ($value ?? '0'), 4, false);
    }

    public function date(?CarbonInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '';
    }

    private function number(string $value, int $scale, bool $round): string
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim(preg_replace('/\D/', '', $whole) ?: '0', '0') ?: '0';
        $fraction = preg_replace('/\D/', '', $fraction) ?: '';
        $visible = str_pad(substr($fraction, 0, $scale), $scale, '0');

        if ($round && (int) ($fraction[$scale] ?? '0') >= 5) {
            $combined = $this->increment($whole.$visible);
            $whole = substr($combined, 0, -$scale) ?: '0';
            $visible = substr($combined, -$scale);
        }

        if (! $round) {
            $visible = rtrim($visible, '0');
        }

        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole);
        $formatted = $visible === '' ? $grouped : $grouped.','.$visible;

        return $negative && $formatted !== '0' ? '-'.$formatted : $formatted;
    }

    private function increment(string $digits): string
    {
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return $digits;
            }
            $digits[$index] = '0';
        }

        return '1'.$digits;
    }
}
