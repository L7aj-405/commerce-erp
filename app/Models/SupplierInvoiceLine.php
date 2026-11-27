<?php

namespace App\Models;

use App\Enums\SupplierInvoiceCostNature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One priced line of a supplier invoice; frozen as soon as its invoice leaves draft. */
class SupplierInvoiceLine extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $line): void {
            $invoiceId = (int) ($line->getRawOriginal('supplier_invoice_id') ?? $line->getAttributes()['supplier_invoice_id'] ?? 0);
            if ($invoiceId > 0 && ! SupplierInvoice::isDraftRow($invoiceId)) {
                throw new LogicException('Lines of an approved or void supplier invoice are immutable.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'cost_nature' => SupplierInvoiceCostNature::class,
            'quantity' => 'decimal:4',
            'unit_price_ht' => 'decimal:4',
            'tax_rate_percent' => 'decimal:4',
            'line_ht' => 'decimal:4',
            'line_tva' => 'decimal:4',
            'line_ttc' => 'decimal:4',
        ];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
