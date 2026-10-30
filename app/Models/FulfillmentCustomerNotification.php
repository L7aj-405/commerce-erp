<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F1.2 message to the customer about one shipment: automatic (at most one per
 * (shipment, event), written by FulfillmentCustomerNotifier and its job) or a
 * manual "Partager au client" (one per operator submit, written by
 * ShareFulfillmentWithCustomerAction). dedupe_key is the idempotency key.
 */
class FulfillmentCustomerNotification extends Model
{
    public const EVENT_HANDED_TO_CARRIER = 'handed_to_carrier';
    public const EVENT_DELIVERED = 'delivered';
    public const EVENTS = [self::EVENT_HANDED_TO_CARRIER, self::EVENT_DELIVERED];

    public const ORIGIN_AUTOMATIC = 'automatic';
    public const ORIGIN_MANUAL = 'manual';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    /** WhatsApp click-to-chat opened by the operator: the ERP cannot know whether the message was then sent. */
    public const STATUS_OPENED = 'opened';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attachment_ids' => 'array'];
    }

    public static function automaticKey(string $event): string
    {
        return "auto:{$event}";
    }

    public static function shareKey(string $clientOperationId): string
    {
        return 'share:'.strtolower($clientOperationId);
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function fulfillment(): BelongsTo { return $this->belongsTo(Fulfillment::class); }
    public function sentBy(): BelongsTo { return $this->belongsTo(User::class, 'sent_by_user_id'); }
}
