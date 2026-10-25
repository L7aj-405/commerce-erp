<?php

namespace App\Models;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
use App\Enums\AttentionType;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A W4.1 attention item (one condition episode). Written only by
 * QuotationAttentionDetector (detection/resolution) and
 * HandleAttentionItemAction (snooze, dismiss, follow-up bridge); see the
 * migration for the episode / active_key semantics.
 */
class AttentionItem extends Model
{
    use ScopesToActiveOrganization;

    public const SUBJECT_QUOTATION = 'quotation';

    public const SUBJECT_INVOICE = 'invoice';

    public const SUBJECT_SALES_ORDER = 'sales_order';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => AttentionType::class,
            'severity' => AttentionSeverity::class,
            'status' => AttentionStatus::class,
            'metadata' => 'array',
            'episode' => 'integer',
            'detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'dismissed_at' => 'datetime',
            'notification_processed_at' => 'datetime',
            'notified_at' => 'datetime',
            'urgency' => 'integer',
            'ownership_resolved_at' => 'datetime',
            'critical_since' => 'datetime',
            'escalated_at' => 'datetime',
            'escalation_exempt' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** W4.3: the user currently responsible (see AttentionOwnership). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
