<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseReplenishmentOverride extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['minimum_quantity' => 'decimal:4'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
