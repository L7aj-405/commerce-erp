<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderSequence extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
