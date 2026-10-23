<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class McpOAuthAuthorizationCode extends Model
{
    use HasUlids;

    protected $table = 'mcp_oauth_authorization_codes';
    protected $guarded = ['*'];
    protected $hidden = ['code_hash', 'code_challenge'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(McpConnection::class, 'mcp_connection_id'); }
    public function client(): BelongsTo { return $this->belongsTo(McpOAuthClient::class, 'client_id'); }
}
