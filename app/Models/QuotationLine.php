<?php

namespace App\Models;

use App\Enums\PriceInputMode;
use App\Enums\QuotationLineType;
use App\Enums\SalesOrderDiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'line_type' => QuotationLineType::class,
            'price_input_mode' => PriceInputMode::class,
            'discount_type' => SalesOrderDiscountType::class,
            'quantity' => 'decimal:4',
            'unit_price_excl_tax' => 'decimal:4',
            'unit_price_incl_tax' => 'decimal:4',
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function nonStockItem(): BelongsTo
    {
        return $this->belongsTo(NonStockItem::class);
    }
}
