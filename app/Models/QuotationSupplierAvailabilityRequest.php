<?php

namespace App\Models;

use App\Enums\QuotationSupplierAvailabilityStatus;
use App\Models\Concerns\RoutesToSandboxInventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Internal tracking of a supplier availability check — pre-sale sourcing
 * evidence — for ONE line of exactly one context: a draft Devis line
 * (quotation_id / quotation_line_id) or a POS cart line (sales_order_id /
 * sales_order_line_id). Never rendered on a customer document and never a
 * purchase order, a reservation or a stock movement.
 */
class QuotationSupplierAvailabilityRequest extends Model
{
    use RoutesToSandboxInventory;

    public function sandboxRootLinks(): array
    {
        return ['quotation_id' => 'quotations', 'sales_order_id' => 'sales_orders'];
    }

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

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    /** The procurement requirement this answer is the evidence of (at most one, DB-unique). */
    public function derivedProcurement(): HasOne
    {
        return $this->hasOne(SalesOrderProcurement::class, 'source_supplier_request_id');
    }

    /** Evidence gathered from a POS cart (not from a Devis). */
    public function isCartEvidence(): bool
    {
        return $this->sales_order_line_id !== null;
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
    public function matchesLineArticle(QuotationLine|SalesOrderLine $line): bool
    {
        return $this->matchesArticle($line->product_variant_id, $line instanceof QuotationLine ? $line->non_stock_item_id : null);
    }

    /** The request was made for this article (variant / non-stock item snapshot). */
    public function matchesArticle(?int $productVariantId, ?int $nonStockItemId): bool
    {
        return (int) $this->product_variant_id === (int) $productVariantId
            && (int) $this->non_stock_item_id === (int) $nonStockItemId;
    }
}
