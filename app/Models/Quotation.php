<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\ScopesToActiveStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use ScopesToActiveStore;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'seller_snapshot' => 'array',
            'revision_number' => 'integer',
            'subtotal_excl_tax' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total_incl_tax' => 'decimal:4',
            'issued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'converted_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('position');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function convertedSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id');
    }

    /** The first issued Devis of this commercial proposal chain (set on revisions only). */
    public function rootQuotation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_quotation_id');
    }

    /** The immediate predecessor a revision Devis was created from. */
    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revised_from_quotation_id');
    }

    /** Revisions created directly from this Devis. */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revised_from_quotation_id');
    }

    /** True once the validity date has passed, regardless of the stored status. */
    public function isPastValidity(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    /** This Devis is a revision (Révision 1, 2, …) rather than an initial proposal. */
    public function isRevision(): bool
    {
        return (int) $this->revision_number > 0;
    }

    /** Id of the head of this Devis' commercial proposal chain. */
    public function chainRootId(): int
    {
        return (int) ($this->root_quotation_id ?? $this->getKey());
    }
}
