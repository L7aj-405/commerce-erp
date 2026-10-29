<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shipping proof / carrier document stored on a private disk. */
class FulfillmentAttachment extends Model
{
    use HasUlids;

    public const CATEGORIES = ['shipping_proof', 'carrier_document', 'other'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function fulfillment(): BelongsTo { return $this->belongsTo(Fulfillment::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
}
