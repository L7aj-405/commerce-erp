<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class McpOAuthClient extends Model
{
    public const AUTH_NONE = 'none';
    public const AUTH_SECRET_POST = 'client_secret_post';
    public const AUTH_SECRET_BASIC = 'client_secret_basic';

    protected $table = 'mcp_oauth_clients';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['*'];
    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isPublic(): bool
    {
        return $this->token_endpoint_auth_method === self::AUTH_NONE;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null;
    }
}
