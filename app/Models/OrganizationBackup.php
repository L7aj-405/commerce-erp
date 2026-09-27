<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationBackup extends Model
{
    public const TYPE_MANUAL = 'manual';

    public const TYPE_SCHEDULED = 'scheduled';

    public const TYPE_PRE_RESTORE = 'pre_restore';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED_BY_RETENTION = 'deleted_by_retention';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'notified_failure_at' => 'datetime',
            'size_bytes' => 'integer',
            'format_version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cloudCopies(): HasMany
    {
        return $this->hasMany(OrganizationBackupCloudCopy::class);
    }
}
