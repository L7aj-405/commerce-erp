<?php

namespace App\Models;

use App\Enums\FinancePaymentInstrumentState;
use App\Enums\FinancePaymentInstrumentType;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePaymentInstrument extends Model
{
    use ScopesToActiveOrganization;
    protected $guarded = ['*'];
    protected $hidden = ['clearing_idempotency_key'];
    protected function casts(): array
    {
        return ['type' => FinancePaymentInstrumentType::class, 'state' => FinancePaymentInstrumentState::class,
            'amount' => 'decimal:4', 'issue_date' => 'date', 'maturity_date' => 'date',
            'presented_at' => 'datetime', 'cleared_at' => 'datetime', 'cancelled_at' => 'datetime', 'returned_at' => 'datetime'];
    }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function obligation(): BelongsTo { return $this->belongsTo(FinanceObligation::class); }
    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
    public function outgoingPayment(): BelongsTo { return $this->belongsTo(FinanceOutgoingPayment::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }
}
