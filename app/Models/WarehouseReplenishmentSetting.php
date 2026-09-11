<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseReplenishmentSetting extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'auto_replenish' => 'boolean',
            'default_minimum_quantity' => 'decimal:4',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
