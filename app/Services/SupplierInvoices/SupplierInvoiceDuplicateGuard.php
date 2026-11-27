<?php

namespace App\Services\SupplierInvoices;

use App\Support\Decimal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Normalizer;

/**
 * Pure duplicate helpers (no database): number normalization, the hard-duplicate key, and soft-warning
 * detectors over candidate arrays the caller loaded (same supplier, same organization). The hard
 * guarantee is the unique (organization_id, supplier_id, dedupe_key) index, not this class.
 *
 * Candidate shape: ['id' => int, 'invoice_number' => string, 'invoice_date' => string|CarbonInterface,
 * 'total_ttc' => string]. Warning shape: ['code' => string, 'message' => string, 'invoice_id' => mixed].
 */
final class SupplierInvoiceDuplicateGuard
{
    public const SAME_TOTAL = 'same_total_nearby';

    public const OTHER_YEAR = 'same_number_other_year';

    public const LEADING_ZEROS = 'same_number_leading_zeros';

    /** Unicode NFKC, upper-case, keep letters and digits only (leading zeros are significant and kept). */
    public function normalize(string $number): string
    {
        if (class_exists(Normalizer::class)) {
            $number = Normalizer::normalize($number, Normalizer::FORM_KC) ?: $number;
        }

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper($number, 'UTF-8'));
    }

    /** NORMALIZED|YYYY (the year of the invoice date, so numbering restarting each year is not a duplicate). */
    public function key(string $number, CarbonInterface|string $invoiceDate): string
    {
        $normalized = $this->normalize($number);
        if ($normalized === '') {
            throw ValidationException::withMessages(['invoice_number' => 'Le numéro de facture doit contenir au moins une lettre ou un chiffre.']);
        }

        return $normalized.'|'.$this->date($invoiceDate)->format('Y');
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{code: string, message: string, invoice_id: mixed}>
     */
    public function warnings(string $number, CarbonInterface|string $invoiceDate, string $totalTtc, array $candidates, int $windowDays): array
    {
        return [
            ...$this->sameTotalDifferentNumber($number, $invoiceDate, $totalTtc, $candidates, $windowDays),
            ...$this->sameNumberOtherYear($number, $invoiceDate, $candidates),
            ...$this->sameDigitsIgnoringLeadingZeros($number, $invoiceDate, $candidates),
        ];
    }

    /**
     * Same total within the window but a different number (possible re-keyed number of the same bill).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{code: string, message: string, invoice_id: mixed}>
     */
    public function sameTotalDifferentNumber(string $number, CarbonInterface|string $invoiceDate, string $totalTtc, array $candidates, int $windowDays): array
    {
        $normalized = $this->normalize($number);
        $date = $this->date($invoiceDate);
        $out = [];
        foreach ($candidates as $candidate) {
            if ($this->normalize((string) $candidate['invoice_number']) === $normalized
                || Decimal::compare($candidate['total_ttc'], $totalTtc) !== 0
                || $this->daysApart($date, $this->date($candidate['invoice_date'])) > $windowDays) {
                continue;
            }
            $out[] = $this->warning(self::SAME_TOTAL, "Une facture n° {$candidate['invoice_number']} du même fournisseur a le même total à moins de {$windowDays} jours.", $candidate);
        }

        return $out;
    }

    /**
     * Same normalized number dated in another year (the hard key does not block it).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{code: string, message: string, invoice_id: mixed}>
     */
    public function sameNumberOtherYear(string $number, CarbonInterface|string $invoiceDate, array $candidates): array
    {
        $normalized = $this->normalize($number);
        $year = $this->date($invoiceDate)->format('Y');
        $out = [];
        foreach ($candidates as $candidate) {
            $candidateDate = $this->date($candidate['invoice_date']);
            if ($this->normalize((string) $candidate['invoice_number']) !== $normalized || $candidateDate->format('Y') === $year) {
                continue;
            }
            $out[] = $this->warning(self::OTHER_YEAR, "Le n° {$candidate['invoice_number']} existe déjà pour ce fournisseur en {$candidateDate->format('Y')}.", $candidate);
        }

        return $out;
    }

    /**
     * Same number once leading zeros are ignored ("00123" vs "123"), same year or not.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{code: string, message: string, invoice_id: mixed}>
     */
    public function sameDigitsIgnoringLeadingZeros(string $number, CarbonInterface|string $invoiceDate, array $candidates): array
    {
        $normalized = $this->normalize($number);
        $stripped = ltrim($normalized, '0');
        $out = [];
        foreach ($candidates as $candidate) {
            $other = $this->normalize((string) $candidate['invoice_number']);
            if ($stripped === '' || $other === $normalized || ltrim($other, '0') !== $stripped) {
                continue;
            }
            $out[] = $this->warning(self::LEADING_ZEROS, "Le n° {$candidate['invoice_number']} est identique à zéros de tête près.", $candidate);
        }

        return $out;
    }

    private function warning(string $code, string $message, array $candidate): array
    {
        return ['code' => $code, 'message' => $message, 'invoice_id' => $candidate['id'] ?? null];
    }

    private function date(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date instanceof CarbonInterface ? $date->format('Y-m-d') : $date, 'UTC')->startOfDay();
    }

    private function daysApart(Carbon $a, Carbon $b): int
    {
        return intdiv(abs($a->getTimestamp() - $b->getTimestamp()), 86400);
    }
}
