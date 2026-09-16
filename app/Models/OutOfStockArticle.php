<?php

namespace App\Models;

use App\Enums\OutOfStockArticleStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Articles hors stock" (Part B of the WooCommerce/manual-ops brief): an agent
 * flagged a Custom Sales Order line — an article typed in free text because it
 * is not yet a catalogue Product/ProductVariant — as needing to be sourced or
 * created. Lives entirely outside the immutable inventory ledger: resolving a
 * request only stamps which real ProductVariant it became, it never creates
 * stock (see ResolveOutOfStockArticleAction).
 */
class OutOfStockArticle extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => OutOfStockArticleStatus::class,
            'requested_quantity' => 'decimal:4',
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

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function resolvedProductVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'resolved_product_variant_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
