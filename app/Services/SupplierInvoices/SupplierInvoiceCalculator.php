<?php

namespace App\Services\SupplierInvoices;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Pure supplier-invoice arithmetic (no database). Money is a decimal string; each line is rounded
 * half-up at the money scale (2) from the EXACT product, then totals are plain sums of rounded lines.
 * Outputs are canonical 4-decimal strings (DECIMAL(19,4) storage shape).
 */
final class SupplierInvoiceCalculator
{
    /**
     * @param  list<array{quantity: int|string, unit_price_ht: int|string, tax_rate_percent: int|string}>  $lines
     * @return array{lines: list<array{line_ht: string, line_tva: string, line_ttc: string}>, subtotal_ht: string, total_tva: string, rounding_adjustment: string, total_ttc: string}
     */
    public function calculate(array $lines, int|string $roundingAdjustment = '0', string $tolerance = '0.05'): array
    {
        $adjustment = $this->boundedAdjustment($roundingAdjustment, $tolerance);

        $computed = [];
        $subtotal = $tva = $ttc = Decimal::normalize('0');
        foreach (array_values($lines) as $index => $line) {
            $row = $this->calculateLine($line, "lines.{$index}");
            $subtotal = Decimal::add($subtotal, $row['line_ht']);
            $tva = Decimal::add($tva, $row['line_tva']);
            $ttc = Decimal::add($ttc, $row['line_ttc']);
            $computed[] = $row;
        }

        $total = Decimal::add($ttc, $adjustment);
        if (Decimal::compare($total, '0') < 0) {
            throw ValidationException::withMessages(['rounding_adjustment' => "L'arrondi ne peut pas rendre le total TTC négatif."]);
        }

        return [
            'lines' => $computed,
            'subtotal_ht' => $subtotal,
            'total_tva' => $tva,
            'rounding_adjustment' => $adjustment,
            'total_ttc' => $total,
        ];
    }

    /**
     * @param  array{quantity: int|string, unit_price_ht: int|string, tax_rate_percent: int|string}  $line
     * @return array{line_ht: string, line_tva: string, line_ttc: string}
     */
    public function calculateLine(array $line, string $field = 'line'): array
    {
        $quantity = $this->decimal($line['quantity'] ?? null, "{$field}.quantity", 'La quantité');
        $price = $this->decimal($line['unit_price_ht'] ?? null, "{$field}.unit_price_ht", 'Le prix unitaire HT');
        $rate = $this->decimal($line['tax_rate_percent'] ?? null, "{$field}.tax_rate_percent", 'Le taux de TVA');

        if (Decimal::compare($quantity, '0') <= 0) {
            throw ValidationException::withMessages(["{$field}.quantity" => 'La quantité doit être supérieure à zéro.']);
        }
        if (Decimal::compare($price, '0') < 0) {
            throw ValidationException::withMessages(["{$field}.unit_price_ht" => 'Le prix unitaire HT ne peut pas être négatif.']);
        }
        if (Decimal::compare($rate, '0') < 0 || Decimal::compare($rate, '100') > 0) {
            throw ValidationException::withMessages(["{$field}.tax_rate_percent" => 'Le taux de TVA doit être compris entre 0 et 100.']);
        }

        // Exact products (bcmath, scale = sum of operand scales) then ONE half-up rounding: avoids the
        // double rounding a 4-decimal intermediate would introduce at x.xx5 boundaries. Operands are
        // non-negative here, so adding half an ulp then truncating is half-up.
        $lineHt = $this->roundHalfUp(bcmul($quantity, $price, 8));
        $lineTva = $this->roundHalfUp(bcdiv(bcmul($lineHt, $rate, 6), '100', 8));

        return [
            'line_ht' => $lineHt,
            'line_tva' => $lineTva,
            'line_ttc' => Decimal::add($lineHt, $lineTva),
        ];
    }

    public function controlTotalMatches(int|string $controlTotal, int|string $computedTotal): bool
    {
        return Decimal::compare($controlTotal, $computedTotal) === 0;
    }

    /** Approval requires the control total (printed on the paper invoice) to equal the computed total exactly. */
    public function assertControlTotal(int|string|null $controlTotal, int|string $computedTotal): void
    {
        if ($controlTotal === null || $controlTotal === '') {
            throw ValidationException::withMessages(['control_total_ttc' => 'Le total TTC de la facture papier est obligatoire pour approuver.']);
        }
        $control = $this->decimal($controlTotal, 'control_total_ttc', 'Le total TTC de contrôle');
        if (! $this->controlTotalMatches($control, $computedTotal)) {
            throw ValidationException::withMessages(['control_total_ttc' => 'Le total TTC calculé ('.Decimal::round($computedTotal, 2).') diffère du total de la facture ('.Decimal::round($control, 2).').']);
        }
    }

    private function boundedAdjustment(int|string $adjustment, string $tolerance): string
    {
        $adjustment = $this->decimal($adjustment === '' ? '0' : $adjustment, 'rounding_adjustment', "L'arrondi");
        $tolerance = Decimal::nonNegative($tolerance, 'rounding_tolerance');
        if (Decimal::compare(ltrim($adjustment, '-'), $tolerance) > 0) {
            throw ValidationException::withMessages(['rounding_adjustment' => "L'arrondi ne peut pas dépasser ±".Decimal::round($tolerance, 2).'.']);
        }

        return $adjustment;
    }

    private function roundHalfUp(string $exact): string
    {
        return Decimal::normalize(bcadd($exact, '0.005', 2));
    }

    private function decimal(mixed $value, string $field, string $label): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => "{$label} est obligatoire (nombre décimal sous forme de texte)."]);
        }
        try {
            return Decimal::normalize($value, $field);
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => "{$label} doit être un nombre décimal (4 décimales maximum)."]);
        }
    }
}
