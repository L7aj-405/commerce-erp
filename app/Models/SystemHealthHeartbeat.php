<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHealthHeartbeat extends Model
{
    protected $guarded = ['*'];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'metadata' => 'array'];
    }

    public static function beat(string $key, array $metadata = []): void
    {
        $heartbeat = static::query()->find($key) ?? new static;
        $heartbeat->key = $key;
        $heartbeat->last_seen_at = now();
        $heartbeat->metadata = $metadata;
        $heartbeat->save();
    }
}
