<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function sourceOutMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'source_out_movement_id');
    }

    public function destinationInMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'destination_in_movement_id');
    }
}
