<?php

namespace App\Services\CatalogImport;

use InvalidArgumentException;

class ExactDecimalParser
{
    public function parse(mixed $value): string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            throw new InvalidArgumentException('Prix invalide.');
        }

        /*
         * Remove supported currency suffixes.
         *
         * Examples:
         * 2,400.00 DH
         * 900.00 Dhs
         * 1 200,50 MAD
         */
        $raw = preg_replace(
            '/\s*(MAD|DHS?|DH)\s*$/iu',
            '',
            $raw
        );

        /*
         * Remove normal spaces, NBSP and narrow NBSP.
         *
         * 1 200,50      -> 1200,50
         * 1 200,50      -> 1200,50
         */
        $raw = str_replace(
            ["\u{00A0}", "\u{202F}", ' '],
            '',
            trim($raw)
        );

        if ($raw === '') {
            throw new InvalidArgumentException('Prix invalide.');
        }

        /*
         * Only digits with optional comma/dot separators.
         */
        if (! preg_match('/^\d+(?:[.,]\d+)*(?:[.,]\d+)?$/u', $raw)) {
            throw new InvalidArgumentException('Prix invalide.');
        }

        $commaPosition = strrpos($raw, ',');
        $dotPosition = strrpos($raw, '.');

        /*
         * Both separators exist.
         *
         * 2,400.00  -> 2400.00
         * 2.400,00  -> 2400.00
         */
        if ($commaPosition !== false && $dotPosition !== false) {
            $decimalSeparator = $commaPosition > $dotPosition ? ',' : '.';
            $groupSeparator = $decimalSeparator === ',' ? '.' : ',';

            $raw = str_replace($groupSeparator, '', $raw);
            $raw = str_replace($decimalSeparator, '.', $raw);
        }

        /*
         * Only one type of separator exists.
         */
        elseif ($commaPosition !== false || $dotPosition !== false) {
            $separator = $commaPosition !== false ? ',' : '.';
            $parts = explode($separator, $raw);

            if (count($parts) > 2) {
                /*
                 * Multiple identical separators can represent
                 * thousands grouping only.
                 *
                 * 1,200,000 -> 1200000
                 */
                foreach (array_slice($parts, 1) as $part) {
                    if (strlen($part) !== 3) {
                        throw new InvalidArgumentException(
                            'Format de prix ambigu.'
                        );
                    }
                }

                $raw = implode('', $parts);
            } else {
                $fractionLength = strlen($parts[1]);

                /*
                 * One separator followed by exactly 3 digits is
                 * ambiguous:
                 *
                 * 1,200 could mean 1200 or 1.200
                 *
                 * Do not guess.
                 */
                if ($fractionLength === 3) {
                    throw new InvalidArgumentException(
                        'Format de prix ambigu. Utilisez deux décimales ou aucun séparateur.'
                    );
                }

                if ($fractionLength < 1 || $fractionLength > 4) {
                    throw new InvalidArgumentException(
                        'Le prix doit contenir au maximum quatre décimales.'
                    );
                }

                $raw = $parts[0].'.'.$parts[1];
            }
        }

        /*
         * Final canonical decimal validation.
         */
        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $raw, $matches)) {
            throw new InvalidArgumentException(
                'Le prix doit contenir au maximum quatre décimales.'
            );
        }

        $whole = ltrim($matches[1], '0') ?: '0';

        /*
         * DECIMAL(19,4) => maximum 15 digits before decimal point.
         */
        if (strlen($whole) > 15) {
            throw new InvalidArgumentException(
                'Le prix dépasse la limite autorisée.'
            );
        }

        $fraction = str_pad(
            $matches[2] ?? '',
            4,
            '0'
        );

        return $whole.'.'.$fraction;
    }

    public function compare(string $left, string $right): int
    {
        [$leftWhole, $leftFraction] = explode('.', $left);
        [$rightWhole, $rightFraction] = explode('.', $right);

        return strlen($leftWhole) <=> strlen($rightWhole)
            ?: strcmp($leftWhole, $rightWhole) <=> 0
            ?: strcmp($leftFraction, $rightFraction) <=> 0;
    }
}