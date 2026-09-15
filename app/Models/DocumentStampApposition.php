<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Records that the company stamp was applied to one specific document.
 * Columns are a frozen copy of the stamp version used at the moment of
 * apposition (image path, anchor, offsets, width, rotation) — renderers must
 * read these columns, never the live `OrganizationDocumentStamp`, so a
 * stamped Invoice/Devis keeps rendering identically even after the
 * organization replaces its active stamp. See the migration docblock for the
 * full rationale.
 */
class DocumentStampApposition extends Model
{
    protected $table = 'document_stamp_appositions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'offset_x_mm' => 'decimal:2',
            'offset_y_mm' => 'decimal:2',
            'display_width_mm' => 'decimal:2',
            'rotation_deg' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function stampable(): MorphTo
    {
        return $this->morphTo();
    }

    public function organizationDocumentStamp(): BelongsTo
    {
        return $this->belongsTo(OrganizationDocumentStamp::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
