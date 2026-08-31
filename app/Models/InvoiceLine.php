<?php

namespace App\Models;

use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'line_type' => SalesOrderLineType::class,
            'discount_type' => SalesOrderDiscountType::class,
            'quantity' => 'decimal:4',
            'unit_price_excl_tax' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'subtotal_excl_tax' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'taxable_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_incl_tax' => 'decimal:4',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
