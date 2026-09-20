<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CreditNoteLine extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $rejectIssuedMutation = function (CreditNoteLine $line) {
            if ($line->creditNote()->withoutGlobalScopes()->where('status', 'issued')->exists()) {
                throw new LogicException('Lines of an issued Credit Note are immutable.');
            }
        };

        static::creating($rejectIssuedMutation);
        static::updating($rejectIssuedMutation);
        static::deleting($rejectIssuedMutation);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price_excl_tax' => 'decimal:4', 'unit_price_incl_tax' => 'decimal:4',
            'subtotal_excl_tax' => 'decimal:4', 'discount_amount' => 'decimal:4', 'taxable_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total_incl_tax' => 'decimal:4',
        ];
    }

    public function creditNote(): BelongsTo { return $this->belongsTo(CreditNote::class); }
    public function customerReturnLine(): BelongsTo { return $this->belongsTo(CustomerReturnLine::class); }
}
