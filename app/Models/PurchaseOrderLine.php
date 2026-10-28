<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One ordered quantity of one procurement requirement (and so of one Sales Order line). */
class PurchaseOrderLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function procurement(): BelongsTo { return $this->belongsTo(SalesOrderProcurement::class, 'sales_order_procurement_id'); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class); }
    public function productVariant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
    public function receipts(): HasMany { return $this->hasMany(ProcurementReceipt::class)->orderBy('id'); }
}
