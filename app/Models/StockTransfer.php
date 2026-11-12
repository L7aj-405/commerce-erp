<?php

namespace App\Models;

use App\Models\Concerns\RoutesToSandboxInventory;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use RoutesToSandboxInventory;
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'unit_count' => 'decimal:4',
            'transferred_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** Requests completed by this transfer (none for a direct transfer). */
    public function transferRequests(): HasMany
    {
        // Transfer requests are Production-only; a Test transfer never links to one.
        return $this->hasMany(TransferRequest::class)->when($this->isSandboxInventory(), fn ($query) => $query->whereRaw('1 = 0'));
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }
}
