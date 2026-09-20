<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CustomerReturnLine extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $rejectIssuedSnapshotMutation = function (CustomerReturnLine $line) {
            if (CreditNoteLine::query()
                ->where('customer_return_line_id', $line->getKey())
                ->whereHas('creditNote', fn ($query) => $query->withoutGlobalScopes()->where('status', 'issued'))
                ->exists()) {
                throw new LogicException('A Return line referenced by an issued Credit Note is immutable.');
            }
        };

        static::updating($rejectIssuedSnapshotMutation);
        static::deleting($rejectIssuedSnapshotMutation);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price_excl_tax' => 'decimal:4', 'unit_price_incl_tax' => 'decimal:4',
            'subtotal_excl_tax' => 'decimal:4', 'discount_amount' => 'decimal:4', 'taxable_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total_incl_tax' => 'decimal:4',
        ];
    }

    public function customerReturn(): BelongsTo { return $this->belongsTo(CustomerReturn::class); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class); }
    public function productVariant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
}
