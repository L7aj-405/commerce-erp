<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSocialIdentity extends Model
{
    public const PROVIDER_GOOGLE = 'google';

    protected $guarded = ['*'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
