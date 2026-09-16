<?php

namespace App\Models;

use App\Enums\WooCommerceStockTaskStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "please reflect this on WooCommerce" operational reminder, raised when a
 * Woo-linked ProductVariant loses company stock through a customer sale. Purely
 * an operator checklist: completing a task never mutates inventory and never
 * calls the WooCommerce API — see the class doc on
 * RecordWooCommerceStockTaskAction / CompleteWooCommerceStockTaskAction.
 */
class WooCommerceStockTask extends Model
{
    use ScopesToActiveOrganization;
    protected $table = 'woocommerce_stock_tasks';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => WooCommerceStockTaskStatus::class,
            'quantity_delta' => 'decimal:4',
            'metadata' => 'array',
            'completed_at' => 'datetime',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WooCommerceIntegration::class, 'woocommerce_integration_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
