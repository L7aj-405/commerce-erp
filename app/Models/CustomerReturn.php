<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerReturn extends Model
{
    use ScopesToActiveStore;

    protected $guarded = ['*'];

    protected $hidden = ['client_operation_id'];

    protected function casts(): array
    {
        return [
            'policy_snapshot' => 'array',
            'subtotal_excl_tax' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total_incl_tax' => 'decimal:4',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function lines(): HasMany { return $this->hasMany(CustomerReturnLine::class)->orderBy('position'); }
    public function creditNotes(): HasMany { return $this->hasMany(CreditNote::class); }
    public function refunds(): HasMany { return $this->hasMany(PaymentRefund::class); }
    public function exchange(): HasOne { return $this->hasOne(CustomerExchange::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }
}
