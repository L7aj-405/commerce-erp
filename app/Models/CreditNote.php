<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CreditNote extends Model
{
    use ScopesToActiveStore;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (CreditNote $note) {
            if ($note->getOriginal('status') === 'issued' && $note->isDirty([
                'organization_id', 'store_id', 'customer_return_id', 'invoice_id', 'sales_order_id',
                'credit_note_number', 'credit_note_date', 'currency_code', 'subtotal_excl_tax',
                'discount_total', 'tax_total', 'total_incl_tax', 'reason', 'seller_snapshot',
                'customer_snapshot', 'template_version', 'issued_at', 'issued_by_user_id',
            ])) {
                throw new LogicException('An issued Credit Note is immutable. Create a new accounting document for any correction.');
            }
        });

        static::deleting(function (CreditNote $note) {
            if ($note->status === 'issued') {
                throw new LogicException('An issued Credit Note cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'credit_note_date' => 'date', 'seller_snapshot' => 'array', 'customer_snapshot' => 'array',
            'subtotal_excl_tax' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4',
            'total_incl_tax' => 'decimal:4', 'issued_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function customerReturn(): BelongsTo { return $this->belongsTo(CustomerReturn::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function lines(): HasMany { return $this->hasMany(CreditNoteLine::class)->orderBy('position'); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by_user_id'); }
}
