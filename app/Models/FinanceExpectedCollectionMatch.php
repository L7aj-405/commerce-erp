<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceExpectedCollectionMatch extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array { return ['amount' => 'decimal:4']; }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function expectedCollection(): BelongsTo { return $this->belongsTo(FinanceExpectedCollection::class, 'expected_collection_id'); }
    public function paymentAllocation(): BelongsTo { return $this->belongsTo(PaymentAllocation::class); }
    public function matchedBy(): BelongsTo { return $this->belongsTo(User::class, 'matched_by_user_id'); }
}
