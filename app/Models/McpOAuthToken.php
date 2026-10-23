<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One access token and (optionally) its paired refresh token; rotation creates a new row in the same family. */
final class McpOAuthToken extends Model
{
    use HasUlids;

    protected $table = 'mcp_oauth_tokens';
    protected $guarded = ['*'];
    protected $hidden = ['access_token_hash', 'refresh_token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'access_expires_at' => 'immutable_datetime',
            'refresh_expires_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(McpConnection::class, 'mcp_connection_id'); }
    public function client(): BelongsTo { return $this->belongsTo(McpOAuthClient::class, 'client_id'); }
}
