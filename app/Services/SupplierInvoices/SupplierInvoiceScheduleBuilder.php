<?php

namespace App\Services\SupplierInvoices;

use App\Support\Decimal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Pure payment-schedule validation/defaults (no database). Installments must add up to the invoice
 * payable total EXACTLY; nothing is silently adjusted.
 */
final class SupplierInvoiceScheduleBuilder
{
    /**
     * Validate and normalize installments: amounts > 0, due_date >= invoice_date, sum == total exactly.
     * Returns them ordered by (due_date, original index) with 1-based `position` and 4-decimal `amount`;
     * any extra keys (payment method, account...) are preserved.
     *
     * @param  list<array<string, mixed>>  $installments
     * @return list<array<string, mixed>>
     */
    public function validate(CarbonInterface|string $invoiceDate, int|string $totalTtc, array $installments): array
    {
        $invoiceDay = $this->day($invoiceDate, 'invoice_date');
        $total = Decimal::normalize($totalTtc, 'total_ttc');

        if ($installments === []) {
            throw ValidationException::withMessages(['installments' => "L'échéancier doit contenir au moins une échéance."]);
        }

        $rows = [];
        $sum = Decimal::normalize('0');
        foreach (array_values($installments) as $index => $installment) {
            $field = "installments.{$index}";
            $due = $this->day($installment['due_date'] ?? null, "{$field}.due_date");
            $amount = $this->amount($installment['amount'] ?? null, "{$field}.amount");
            if ($due < $invoiceDay) {
                throw ValidationException::withMessages(["{$field}.due_date" => "L'échéance ne peut pas précéder la date de la facture."]);
            }
            $sum = Decimal::add($sum, $amount);
            $rows[] = ['__index' => $index, '__due' => $due->format('Y-m-d'), 'installment' => [...$installment, 'due_date' => $due->format('Y-m-d'), 'amount' => $amount]];
        }

        if (Decimal::compare($sum, $total) !== 0) {
            throw ValidationException::withMessages(['installments' => 'La somme des échéances ('.Decimal::round($sum, 2).') doit être égale au total TTC ('.Decimal::round($total, 2).').']);
        }

        usort($rows, fn (array $a, array $b) => [$a['__due'], $a['__index']] <=> [$b['__due'], $b['__index']]);

        return array_map(fn (array $row, int $position) => [...$row['installment'], 'position' => $position + 1], $rows, array_keys($rows));
    }

    /**
     * One installment for the whole payable total at invoice_date + termDays. Without a known term the
     * user must provide an explicit due date (error).
     *
     * @return list<array{position: int, due_date: string, amount: string}>
     */
    public function defaultSchedule(CarbonInterface|string $invoiceDate, int|string $totalTtc, ?int $termDays): array
    {
        $invoiceDay = $this->day($invoiceDate, 'invoice_date');
        $total = Decimal::normalize($totalTtc, 'total_ttc');

        if ($termDays === null) {
            throw ValidationException::withMessages(['installments' => "Aucun délai de paiement connu pour ce fournisseur : saisissez une date d'échéance."]);
        }
        if ($termDays < 0) {
            throw ValidationException::withMessages(['installments' => 'Le délai de paiement ne peut pas être négatif.']);
        }
        if (Decimal::compare($total, '0') <= 0) {
            throw ValidationException::withMessages(['installments' => 'Le total TTC doit être supérieur à zéro pour générer une échéance.']);
        }

        return [['position' => 1, 'due_date' => $invoiceDay->addDays($termDays)->format('Y-m-d'), 'amount' => $total]];
    }

    private function amount(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => "Le montant de l'échéance est obligatoire."]);
        }
        try {
            $amount = Decimal::normalize($value, $field);
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => "Le montant de l'échéance doit être un nombre décimal (4 décimales maximum)."]);
        }
        if (Decimal::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages([$field => "Le montant de l'échéance doit être supérieur à zéro."]);
        }

        return $amount;
    }

    private function day(mixed $value, string $field): Carbon
    {
        if ($value instanceof CarbonInterface) {
            $value = $value->format('Y-m-d');
        }
        try {
            $parsed = is_string($value) ? Carbon::createFromFormat('!Y-m-d', $value, 'UTC') : false;
        } catch (\Throwable) {
            $parsed = false;
        }
        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Date invalide (format AAAA-MM-JJ attendu).']);
        }

        return $parsed;
    }
}
