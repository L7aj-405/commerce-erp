<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\ScopesToActiveStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Invoice extends Model
{
    use ScopesToActiveStore;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'version' => 'integer',
            'invoice_date' => 'date',
            'subtotal_excl_tax' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total_incl_tax' => 'decimal:4',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'seller_snapshot' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(InvoiceFamily::class, 'invoice_family_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderRevision(): BelongsTo
    {
        return $this->belongsTo(SalesOrderRevision::class);
    }

    public function salesOrderAddendum(): BelongsTo
    {
        return $this->belongsTo(SalesOrderAddendum::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** The originally issued Invoice this record was created to correct. */
    public function correctedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_invoice_id');
    }

    /** The next correction that directly replaces this Invoice, if any. */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrected_invoice_id')->latestOfMany();
    }

    /**
     * Whether/how the company stamp was applied to THIS specific Invoice row.
     * A correction gets its own new row (see StartInvoiceCorrectionAction) and
     * therefore never inherits this — it needs its own explicit apposition.
     */
    public function stampApposition(): MorphOne
    {
        return $this->morphOne(DocumentStampApposition::class, 'stampable');
    }
}
