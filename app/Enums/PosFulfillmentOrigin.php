<?php

namespace App\Enums;

use Illuminate\Validation\ValidationException;

/**
 * Where the goods of a POS order physically leave inventory — independent of
 * HOW the customer receives them (`pos_fulfillment_mode`: pickup / delivery).
 */
enum PosFulfillmentOrigin: string
{
    /** Every unit is gathered at the POS warehouse (Showroom) before hand-over or dispatch. */
    case Showroom = 'showroom';

    /** Units are dispatched from the warehouse that holds their reservation. */
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::Showroom => 'Depuis le Showroom',
            self::Direct => 'Expédition directe',
        };
    }

    /**
     * Default per delivery method, and the effective origin of legacy orders
     * stored before the choice existed (NULL): pickup was always prepared at
     * the Showroom, delivery always shipped from the allocated warehouse.
     */
    public static function defaultFor(?string $mode): self
    {
        return $mode === 'delivery' ? self::Direct : self::Showroom;
    }

    /**
     * A pickup is handed over at the Showroom counter, so its goods must be
     * there: direct dispatch is meaningless for it and is refused explicitly
     * (never silently switched).
     */
    public static function assertSupported(?string $mode, self $origin, string $field = 'fulfillment_origin'): void
    {
        if (($mode ?? 'pickup') !== 'delivery' && $origin === self::Direct) {
            throw ValidationException::withMessages([
                $field => 'Expédition directe impossible pour un retrait au Showroom : le client récupère sa commande au comptoir, les articles doivent donc y être transférés. Choisissez « Depuis le Showroom » ou passez en livraison.',
            ]);
        }
    }
}
