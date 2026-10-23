<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class McpIdempotencyRecord extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['response' => 'array'];
    }
}
