<?php

namespace App\Services\Commissions;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

final class CommissionTierValidator
{
    /**
     * Boundaries are deterministic: lower inclusive, upper exclusive.
     * A null first minimum means -infinity; a null last maximum means +infinity.
     *
     * @param  list<array<string, mixed>>  $tiers
     * @return list<array{min_margin_rate:?string,max_margin_rate:?string,commission_rate:string,sort_order:int}>
     */
    public function validate(array $tiers): array
    {
        if ($tiers === []) {
            throw ValidationException::withMessages(['tiers' => 'Au moins une tranche est requise.']);
        }

        $normalized = [];
        foreach (array_values($tiers) as $index => $tier) {
            try {
                $min = $this->nullableDecimal($tier['min_margin_rate'] ?? null);
                $max = $this->nullableDecimal($tier['max_margin_rate'] ?? null);
                $rate = Decimal::normalize((string) ($tier['commission_rate'] ?? ''));
            } catch (ValidationException) {
                throw ValidationException::withMessages(["tiers.$index" => 'Les taux doivent être des nombres décimaux valides.']);
            }

            if (Decimal::compare($rate, '0') < 0 || Decimal::compare($rate, '100') > 0) {
                throw ValidationException::withMessages(["tiers.$index.commission_rate" => 'La commission doit être comprise entre 0 % et 100 %.']);
            }
            if ($min !== null && $max !== null && Decimal::compare($max, $min) <= 0) {
                throw ValidationException::withMessages(["tiers.$index.max_margin_rate" => 'La borne maximale doit être supérieure à la borne minimale.']);
            }

            $normalized[] = [
                'min_margin_rate' => $min,
                'max_margin_rate' => $max,
                'commission_rate' => $rate,
                'sort_order' => $index + 1,
            ];
        }

        if ($normalized[0]['min_margin_rate'] !== null) {
            throw ValidationException::withMessages(['tiers' => 'La première tranche doit couvrir les marges jusqu’à −∞.']);
        }
        if ($normalized[array_key_last($normalized)]['max_margin_rate'] !== null) {
            throw ValidationException::withMessages(['tiers' => 'La dernière tranche doit couvrir les marges jusqu’à +∞.']);
        }

        foreach ($normalized as $index => $tier) {
            if ($index > 0 && $tier['min_margin_rate'] === null) {
                throw ValidationException::withMessages(['tiers' => 'Seule la première tranche peut avoir une borne minimale ouverte.']);
            }
            if ($index < count($normalized) - 1 && $tier['max_margin_rate'] === null) {
                throw ValidationException::withMessages(['tiers' => 'Seule la dernière tranche peut avoir une borne maximale ouverte.']);
            }
            if ($index === 0) {
                continue;
            }

            $previousMax = $normalized[$index - 1]['max_margin_rate'];
            $currentMin = $tier['min_margin_rate'];
            $comparison = Decimal::compare((string) $previousMax, (string) $currentMin);
            if ($comparison > 0) {
                throw ValidationException::withMessages(['tiers' => 'Les tranches se chevauchent.']);
            }
            if ($comparison < 0) {
                throw ValidationException::withMessages(['tiers' => 'Une plage de marge n’est pas couverte.']);
            }
        }

        return $normalized;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Decimal::normalize((string) $value);
    }
}
