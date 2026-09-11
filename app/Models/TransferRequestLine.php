<?php

namespace App\Models;

use App\Enums\TransferRequestReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferRequestLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reason' => TransferRequestReason::class,
        ];
    }

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TransferRequest::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }
}
