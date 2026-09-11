<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';

    /** Uppercase French label used on official documents ("Modalité de paiement"). */
    public function documentLabel(): string
    {
        return match ($this) {
            self::Cash => 'ESPÈCES',
            self::Card => 'TPE',
            self::BankTransfer => 'VIREMENT',
            self::Cheque => 'CHÈQUE',
        };
    }

    /** Sentence-case French label for operational UI. */
    public function operationalLabel(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::Card => 'TPE',
            self::BankTransfer => 'Virement',
            self::Cheque => 'Chèque',
        };
    }

    /**
     * Concise document rendering of every distinct method used, e.g.
     * "ESPÈCES / TPE". Order follows the enum declaration; duplicates collapse.
     *
     * @param  iterable<self|string>  $methods
     */
    public static function summary(iterable $methods): string
    {
        $seen = [];
        foreach ($methods as $method) {
            $method = $method instanceof self ? $method : self::from($method);
            $seen[$method->value] = $method;
        }

        return collect(self::cases())
            ->filter(fn (self $case) => isset($seen[$case->value]))
            ->map(fn (self $case) => $case->documentLabel())
            ->implode(' / ');
    }
}
