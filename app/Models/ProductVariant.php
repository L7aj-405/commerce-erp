<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'default_sale_price' => 'decimal:4',
            'regular_sale_price' => 'decimal:4',
            'promotional_sale_price' => 'decimal:4',
            'public_price_ttc' => 'decimal:4',
            'unit_price_ht' => 'decimal:4',
            'status' => CatalogStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function inventoryReservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function channelIdentifiers(): HasMany
    {
        return $this->hasMany(ProductChannelIdentifier::class);
    }
}
