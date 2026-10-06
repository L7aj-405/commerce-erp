<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sound_enabled' => 'boolean',
            'sound_volume' => 'decimal:2',
            'disabled_categories' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
