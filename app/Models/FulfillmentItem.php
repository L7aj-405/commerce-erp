<?php

namespace App\Models;

use App\Models\Concerns\RoutesToSandboxInventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentItem extends Model
{
    use RoutesToSandboxInventory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function fulfillment(): BelongsTo { return $this->belongsTo(Fulfillment::class); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class); }
}
