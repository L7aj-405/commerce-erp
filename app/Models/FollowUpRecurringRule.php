<?php

namespace App\Models;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpRecurrenceFrequency;
use App\Enums\FollowUpRecurringStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A W3 recurring follow-up definition. Mutated only through
 * SaveFollowUpRecurringRuleAction / TransitionFollowUpRecurringRuleAction and
 * FollowUpRecurringGenerator; see the migration for the field semantics.
 * `starts_on`, `ends_on`, `last_generated_on` and `next_occurrence_on` are
 * organization-local calendar dates kept as plain `Y-m-d` strings.
 */
class FollowUpRecurringRule extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'priority' => FollowUpPriority::class,
            'frequency' => FollowUpRecurrenceFrequency::class,
            'status' => FollowUpRecurringStatus::class,
            'interval_count' => 'integer',
            'weekdays' => 'array',
            'month_day' => 'integer',
            'max_occurrences' => 'integer',
            'occurrences_generated' => 'integer',
            'next_generate_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'recurring_rule_id');
    }
}
