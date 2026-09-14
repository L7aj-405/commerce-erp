<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A single calendar month, resolved once into its inclusive [start, end] date
 * boundaries. Every Finance query filters a business `_date` column
 * (sale_date / invoice_date / payment_date) against these two Carbon dates —
 * never against `created_at` or any other system timestamp.
 */
final class FinancePeriod
{
    private function __construct(
        public readonly string $month, // "YYYY-MM"
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public static function fromMonth(string $month): self
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Le mois doit être au format AAAA-MM.']);
        }

        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();

        return new self($month, $start->copy()->startOfMonth(), $start->copy()->endOfMonth());
    }

    public static function current(): self
    {
        return self::fromMonth(Carbon::now()->format('Y-m'));
    }

    /** The day immediately before this period starts — "as of" boundary for an opening balance. */
    public function dayBeforeStart(): Carbon
    {
        return $this->start->copy()->subDay();
    }

    /** French month/year label, e.g. "Septembre 2026". */
    public function label(): string
    {
        return ucfirst($this->start->translatedFormat('F Y'));
    }

    /**
     * @param  list<string>  $months
     * @return list<self>
     */
    public static function fromMonths(array $months): array
    {
        if ($months === []) {
            throw ValidationException::withMessages(['months' => 'Sélectionnez au moins un mois.']);
        }

        return collect($months)
            ->unique()
            ->map(fn (string $month) => self::fromMonth($month))
            ->sortBy(fn (self $period) => $period->month)
            ->values()
            ->all();
    }
}
