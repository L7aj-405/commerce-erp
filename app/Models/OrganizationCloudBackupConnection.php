<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationCloudBackupConnection extends Model
{
    public const PROVIDER_GOOGLE_DRIVE = 'google_drive';

    protected $guarded = ['*'];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'token_payload',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_payload' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function cloudCopies(): HasMany
    {
        return $this->hasMany(OrganizationBackupCloudCopy::class, 'connection_id');
    }
}
