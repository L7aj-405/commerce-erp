<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceOutgoingPaymentAllocation extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array { return ['amount' => 'decimal:4']; }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function outgoingPayment(): BelongsTo { return $this->belongsTo(FinanceOutgoingPayment::class, 'outgoing_payment_id'); }
    public function obligation(): BelongsTo { return $this->belongsTo(FinanceObligation::class, 'obligation_id'); }
}
