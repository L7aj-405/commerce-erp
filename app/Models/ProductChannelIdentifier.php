<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChannelIdentifier extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WooCommerceIntegration::class, 'woocommerce_integration_id');
    }

    protected function casts(): array
    {
        return [
            'remote_modified_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
