<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class McpOperation extends Model
{
    use HasUlids;

    public const TYPE_PRODUCT_UPDATE = 'product_bulk_update';
    public const TYPE_PURCHASE_PRICE_UPDATE = 'purchase_price_bulk_update';
    public const TYPE_PRODUCT_ARCHIVE = 'product_bulk_archive';

    public const STATUS_VALIDATING = 'validating';
    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    public const TERMINAL_STATUSES = [self::STATUS_EXPIRED, self::STATUS_COMPLETED, self::STATUS_PARTIAL, self::STATUS_FAILED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'initiated_by' => 'array',
            'required_scopes' => 'array',
            'preview_expires_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'execute_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'details_purged_at' => 'immutable_datetime',
            'total_items' => 'integer',
            'matched_count' => 'integer',
            'changed_count' => 'integer',
            'unchanged_count' => 'integer',
            'skipped_count' => 'integer',
            'invalid_count' => 'integer',
            'conflict_count' => 'integer',
            'succeeded_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function connection(): BelongsTo { return $this->belongsTo(McpConnection::class, 'mcp_connection_id'); }
    public function items(): HasMany { return $this->hasMany(McpOperationItem::class); }
}
