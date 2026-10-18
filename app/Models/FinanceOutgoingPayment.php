<?php

namespace App\Models;

use App\Enums\FinanceOutgoingPaymentStatus;
use App\Enums\FinancePaymentMethod;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceOutgoingPayment extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];
    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'payment_method' => FinancePaymentMethod::class,
            'status' => FinanceOutgoingPaymentStatus::class,
            'amount' => 'decimal:4',
            'payment_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by_user_id'); }
    public function allocations(): HasMany { return $this->hasMany(FinanceOutgoingPaymentAllocation::class, 'outgoing_payment_id'); }
    public function paymentInstrument(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(FinancePaymentInstrument::class, 'outgoing_payment_id'); }
}
