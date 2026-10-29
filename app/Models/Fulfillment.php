<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** F1.1 shipment / hand-over of (part of) a Sales Order. Mutated only through App\Actions\Fulfillment. */
class Fulfillment extends Model
{
    use HasUlids;

    public const MODE_SIMPLE = 'simple';
    public const MODE_ADVANCED = 'advanced';
    public const MODES = [self::MODE_SIMPLE, self::MODE_ADVANCED];

    public const CHANNEL_CARRIER = 'carrier';
    public const CHANNEL_DIRECT = 'direct';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => FulfillmentStatus::class,
            'package_count' => 'integer',
            'expected_delivery_at' => 'datetime',
            'preparation_started_at' => 'datetime',
            'ready_at' => 'datetime',
            'handed_to_carrier_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'stock_consumed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function carrier(): BelongsTo { return $this->belongsTo(OrganizationContact::class, 'carrier_contact_id'); }
    public function items(): HasMany { return $this->hasMany(FulfillmentItem::class); }
    public function attachments(): HasMany { return $this->hasMany(FulfillmentAttachment::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function handedBy(): BelongsTo { return $this->belongsTo(User::class, 'handed_by_user_id'); }
}
