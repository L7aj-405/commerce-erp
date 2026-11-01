<?php

namespace App\Exceptions\Pos;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Raised when a POS cart is finalized but company stock no longer covers it
 * (another sale consumed / reserved the units since the cart was opened). The
 * whole checkout transaction rolls back: no order confirmation, no payment, no
 * reservation, no stock movement. The cart itself is untouched and recoverable.
 *
 * A ValidationException, so every transport keeps its usual 422 / redirect-back
 * behaviour; `lines` carries the per-article shortage details.
 */
class PosStockConflictException extends ValidationException
{
    public const MESSAGE = 'Le stock a changé depuis l’ouverture du panier.';

    /**
     * @param  list<array{line_id: int, product_variant_id: int, product: string, requested: string, available: string, missing: string}>  $lines
     */
    public function __construct(public readonly array $lines)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('order_id', self::MESSAGE);

        parent::__construct($validator);
    }

    /** @return array{message: string, lines: list<array<string, mixed>>} */
    public function payload(): array
    {
        return ['message' => self::MESSAGE, 'lines' => $this->lines];
    }
}
