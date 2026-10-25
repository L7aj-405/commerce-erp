<?php

namespace App\Models;

use App\Enums\FollowUpPriority;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;

/** W3 organization-wide pre-fill preset for the manual follow-up form (never executed automatically). */
class FollowUpTemplate extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'default_priority' => FollowUpPriority::class,
            'default_due_offset_days' => 'integer',
            'active' => 'boolean',
        ];
    }
}
