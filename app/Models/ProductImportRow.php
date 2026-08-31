<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImportRow extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['raw_data' => 'array', 'normalized_data' => 'array', 'messages' => 'array'];
    }

    public function productImport(): BelongsTo
    {
        return $this->belongsTo(ProductImport::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
