<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class McpConnectionScope extends Model
{
    protected $guarded = ['*'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connection_id');
    }
}
