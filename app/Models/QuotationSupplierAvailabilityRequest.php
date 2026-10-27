<?php

namespace App\Models;

use App\Enums\QuotationSupplierAvailabilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal tracking of a supplier availability check for one Devis line. Never
 * rendered on a customer document and never a purchase order.
 */
class QuotationSupplierAvailabilityRequest extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => QuotationSupplierAvailabilityStatus::class,
            'requested_quantity' => 'decimal:4',
            'confirmed_quantity' => 'decimal:4',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function quotationLine(): BelongsTo
    {
        return $this->belongsTo(QuotationLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** The request was made for the article the line currently carries. */
    public function matchesLineArticle(QuotationLine $line): bool
    {
        return (int) $this->product_variant_id === (int) $line->product_variant_id
            && (int) $this->non_stock_item_id === (int) $line->non_stock_item_id;
    }
}
