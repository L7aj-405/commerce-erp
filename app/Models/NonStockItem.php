<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Enums\PriceInputMode;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisation-scoped library of manually quoted articles that are NOT in the
 * Product catalogue. Zero inventory footprint: no InventoryBalance, no opening
 * stock, no reservations, no warehouse assignment — ever.
 */
class NonStockItem extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price_input_mode' => PriceInputMode::class,
            'status' => CatalogStatus::class,
            'default_price_excl_tax' => 'decimal:4',
            'default_price_incl_tax' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'usage_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function quotationLines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }
}
