<?php

namespace App\Support;

/**
 * French spelling of whole numbers, and of MAD amounts for the
 * "Arrêtée la présente facture à la somme de …" line on invoices.
 *
 * Pure PHP (php-intl / NumberFormatter is not installed in this environment).
 * Traditional orthography: hyphens inside a tens-units compound, "et" for
 * 21/31/41/51/61/71, invariable "mille", plural "cents"/"quatre-vingts" only
 * when they end the number. Covers 0 – 999 999 999 999.
 */
final class FrenchNumberToWords
{
    private const UNITS = [
        0 => 'zéro', 1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq',
        6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf', 10 => 'dix', 11 => 'onze',
        12 => 'douze', 13 => 'treize', 14 => 'quatorze', 15 => 'quinze', 16 => 'seize',
    ];

    private const TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    /** @var array<int, array{0:string,1:string}> singular/plural scale words, index = group position */
    private const SCALES = [
        1 => ['mille', 'mille'],
        2 => ['million', 'millions'],
        3 => ['milliard', 'milliards'],
    ];

    public function words(int $number): string
    {
        if ($number < 0) {
            return 'moins '.$this->words(-$number);
        }
        if ($number < 17) {
            return self::UNITS[$number];
        }

        $groups = [];
        $remaining = $number;
        while ($remaining > 0) {
            $groups[] = $remaining % 1000;
            $remaining = intdiv($remaining, 1000);
        }

        $parts = [];
        for ($position = count($groups) - 1; $position >= 0; $position--) {
            $group = $groups[$position];
            if ($group === 0) {
                continue;
            }

            $isLast = ! $this->hasLowerNonZeroGroup($groups, $position);

            if ($position === 0) {
                $parts[] = $this->threeDigits($group, $isLast);

                continue;
            }

            [$singular, $plural] = self::SCALES[$position];
            if ($position === 1 && $group === 1) {
                $parts[] = $singular; // "mille", never "un mille"
            } else {
                $scaleWord = $group === 1 ? $singular : $plural;
                // "vingt"/"cent" stay invariable before the invariable multiplier
                // "mille", but agree before the nouns "millions"/"milliards".
                $groupIsLast = $position >= 2 && $isLast;
                $parts[] = $this->threeDigits($group, $groupIsLast).' '.$scaleWord;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Spell a decimal MAD amount, e.g.
     * "TROIS MILLE HUIT CENT QUATRE-VINGT-DOUZE DIRHAMS ET CINQUANTE CENTIMES".
     */
    public function mad(int|string $amount): string
    {
        $rounded = Decimal::round((string) $amount, 2);
        $negative = str_starts_with($rounded, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($rounded, '-')), 2, '00');
        $wholeInt = (int) $whole;
        $centimes = (int) str_pad(substr($fraction, 0, 2), 2, '0');

        $text = mb_strtoupper($this->words($wholeInt)).' '.($wholeInt === 1 ? 'DIRHAM' : 'DIRHAMS');
        if ($centimes > 0) {
            $text .= ' ET '.mb_strtoupper($this->words($centimes)).' '.($centimes === 1 ? 'CENTIME' : 'CENTIMES');
        }

        return ($negative ? 'MOINS ' : '').$text;
    }

    /** @param array<int, int> $groups */
    private function hasLowerNonZeroGroup(array $groups, int $position): bool
    {
        for ($lower = $position - 1; $lower >= 0; $lower--) {
            if ($groups[$lower] !== 0) {
                return true;
            }
        }

        return false;
    }

    private function threeDigits(int $number, bool $isLast): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $parts = [];

        if ($hundreds === 1) {
            $parts[] = 'cent';
        } elseif ($hundreds > 1) {
            $plural = $rest === 0 && $isLast ? 'cents' : 'cent';
            $parts[] = self::UNITS[$hundreds].' '.$plural;
        }

        if ($rest > 0) {
            $parts[] = $this->twoDigits($rest, $isLast);
        }

        return implode(' ', $parts);
    }

    private function twoDigits(int $number, bool $isLast): string
    {
        if ($number < 17) {
            return self::UNITS[$number];
        }
        if ($number < 20) {
            return 'dix-'.self::UNITS[$number - 10];
        }

        $tens = intdiv($number, 10);
        $unit = $number % 10;

        if ($tens <= 6) {
            $base = self::TENS[$tens];
            if ($unit === 0) {
                return $base;
            }
            if ($unit === 1) {
                return $base.' et un';
            }

            return $base.'-'.self::UNITS[$unit];
        }

        if ($tens === 7) {
            if ($unit === 0) {
                return 'soixante-dix';
            }
            if ($unit === 1) {
                return 'soixante et onze';
            }

            return 'soixante-'.$this->twoDigits(10 + $unit, $isLast);
        }

        // 80–99
        if ($tens === 8 && $unit === 0) {
            return $isLast ? 'quatre-vingts' : 'quatre-vingt';
        }
        if ($tens === 8) {
            return 'quatre-vingt-'.self::UNITS[$unit];
        }

        return 'quatre-vingt-'.$this->twoDigits(10 + $unit, $isLast);
    }
}
