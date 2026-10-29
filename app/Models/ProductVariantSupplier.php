<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supplier catalog relation (Phase B1): one supplier supplies one product
 * variant on known terms. One canonical row per organization + supplier +
 * variant; deactivated, never deleted. Mutated only through
 * SaveProductVariantSupplierAction.
 */
class ProductVariantSupplier extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'minimum_order_quantity' => 'decimal:4',
            'order_multiple' => 'decimal:4',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
            'priority' => 'integer',
            'quality_rating' => 'integer',
            'reliability_rating' => 'integer',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
}
