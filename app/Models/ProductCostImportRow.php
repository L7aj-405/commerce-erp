<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCostImportRow extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'current_purchase_price' => 'decimal:4',
            'new_purchase_price' => 'decimal:4',
        ];
    }

    public function productCostImport(): BelongsTo
    {
        return $this->belongsTo(ProductCostImport::class);
    }
}
