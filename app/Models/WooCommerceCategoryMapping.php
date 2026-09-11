<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooCommerceCategoryMapping extends Model
{
    protected $table = 'woocommerce_category_mappings';

    protected $guarded = ['*'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WooCommerceIntegration::class, 'woocommerce_integration_id');
    }
}
