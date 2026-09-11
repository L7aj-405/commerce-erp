<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class WooCommerceIntegration extends Model
{
    use ScopesToActiveOrganization;

    public const CHANNEL = 'woocommerce';

    protected $table = 'woocommerce_integrations';

    protected $guarded = ['*'];

    /** The secret is never serialised to the client. */
    protected $hidden = ['consumer_secret'];

    protected function casts(): array
    {
        return [
            'consumer_secret' => 'encrypted',
            'sync_stock' => 'boolean',
            'prices_include_tax' => 'boolean',
            'last_connection_ok' => 'boolean',
            'last_connection_check_at' => 'datetime',
            'last_product_sync_started_at' => 'datetime',
            'last_product_sync_completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function defaultStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_store_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(WooCommerceSyncRun::class, 'woocommerce_integration_id')->latest('id');
    }

    /** REST API base for this store, e.g. https://shop.example.com/wp-json/wc/v3 */
    public function apiBaseUrl(): string
    {
        return rtrim($this->store_url, '/').'/wp-json/'.config('woocommerce.api_version', 'wc/v3');
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query->where('organization_id', Auth::user()->active_organization_id));
    }
}
