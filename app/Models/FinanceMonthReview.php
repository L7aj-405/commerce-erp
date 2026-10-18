<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceMonthReview extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'close_snapshot' => 'array',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by_user_id'); }
    public function reopenedBy(): BelongsTo { return $this->belongsTo(User::class, 'reopened_by_user_id'); }
}
