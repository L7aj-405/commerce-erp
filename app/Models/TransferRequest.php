<?php

namespace App\Models;

use App\Enums\TransferRequestStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferRequest extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => TransferRequestStatus::class,
            'requested_at' => 'datetime',
            'prepared_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferRequestLine::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /** Soft link to the ERP user chosen as chauffeur, if any (name is snapshotted separately). */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    /** The optional Bon de sortie is printable once the request is at least prepared. */
    public function bonDeSortieAvailable(): bool
    {
        return in_array($this->status, [
            TransferRequestStatus::Preparing,
            TransferRequestStatus::Shipped,
            TransferRequestStatus::Received,
        ], true);
    }
}
