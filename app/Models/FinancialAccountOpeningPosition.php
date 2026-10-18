<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccountOpeningPosition extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
