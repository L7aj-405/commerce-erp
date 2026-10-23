<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class McpConnection extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $guarded = ['*'];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function scopeRecords(): HasMany { return $this->hasMany(McpConnectionScope::class); }

    /** @return list<string> */
    public function scopeKeys(): array
    {
        return $this->relationLoaded('scopeRecords')
            ? $this->scopeRecords->pluck('scope')->values()->all()
            : $this->scopeRecords()->orderBy('scope')->pluck('scope')->all();
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
