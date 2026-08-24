<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use App\Support\InventoryQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected $appends = ['available'];

    protected function casts(): array
    {
        return ['on_hand' => 'decimal:4', 'reserved' => 'decimal:4'];
    }

    public function getAvailableAttribute(): string
    {
        return InventoryQuantity::subtract($this->on_hand, $this->reserved);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
