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

    /** Opaque `10xmcp_` bearer secret shown once in Settings. */
    public const AUTH_TOKEN = 'token';
    /** Created by an OAuth consent; reachable only through OAuth access tokens. */
    public const AUTH_OAUTH = 'oauth';

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
    public function oauthClient(): BelongsTo { return $this->belongsTo(McpOAuthClient::class, 'oauth_client_id'); }

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

    public function isOAuth(): bool
    {
        return $this->auth_mode === self::AUTH_OAUTH;
    }
}
