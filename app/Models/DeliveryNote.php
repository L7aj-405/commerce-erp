<?php

namespace App\Models;

use App\Enums\DeliveryNoteStatus;
use App\Models\Concerns\ScopesToActiveStore;
use App\Models\Concerns\ScopesToSandbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryNote extends Model
{
    use ScopesToActiveStore;
    use ScopesToSandbox;

    protected $guarded = ['*'];

    public function sandboxLinks(): array
    {
        return ['sales_order_id' => 'sales_orders'];
    }

    public function sandboxStatuses(): ?array
    {
        return ['draft', 'issued', 'cancelled'];
    }

    public function isTest(): bool
    {
        return $this->sandbox_id !== null;
    }

    protected function casts(): array
    {
        return [
            'status' => DeliveryNoteStatus::class,
            'delivery_date' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'seller_snapshot' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('position');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
