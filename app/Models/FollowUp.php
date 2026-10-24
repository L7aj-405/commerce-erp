<?php

namespace App\Models;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Work Center follow-up. Mutated only through SaveFollowUpAction and
 * TransitionFollowUpAction; see the create_follow_ups_table migration for the
 * due_date / due_at semantics.
 */
class FollowUp extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    // `due_date` is deliberately uncast: a plain `Y-m-d` business calendar date
    // (organization timezone) that must never pass through a datetime cast.

    protected function casts(): array
    {
        return [
            'status' => FollowUpStatus::class,
            'priority' => FollowUpPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function hasDueTime(): bool
    {
        return $this->due_at !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
