<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationBackupCloudCopy extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationBackup(): BelongsTo
    {
        return $this->belongsTo(OrganizationBackup::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OrganizationCloudBackupConnection::class, 'connection_id');
    }
}
