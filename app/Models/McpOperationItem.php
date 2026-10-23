<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class McpOperationItem extends Model
{
    // Preview outcome computed during validation.
    public const PREVIEW_CHANGED = 'changed';
    public const PREVIEW_UNCHANGED = 'unchanged';
    public const PREVIEW_SKIPPED = 'skipped';
    public const PREVIEW_INVALID = 'invalid';
    public const PREVIEW_CONFLICT = 'conflict';

    // Execution lifecycle. Only `ready` items are queued on execute.
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_READY = 'ready';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'locator' => 'array',
            'input_changes' => 'array',
            'proposed_values' => 'array',
            'preview_result' => 'array',
            'result' => 'array',
            'processed_at' => 'immutable_datetime',
            'ordinal' => 'integer',
            'target_id' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function operation(): BelongsTo { return $this->belongsTo(McpOperation::class, 'mcp_operation_id'); }
}
