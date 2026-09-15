<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single version of an organization's company stamp ("cachet"). Rows are
 * append-only: `SaveOrganizationDocumentStampAction` never updates an
 * existing row's image/position — it inserts a new one and deactivates the
 * previous one. This is what lets `DocumentStampApposition` freeze a
 * reference to "the stamp as it was" without that reference ever drifting.
 */
class OrganizationDocumentStamp extends Model
{
    use ScopesToActiveOrganization;

    protected $table = 'organization_document_stamps';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'offset_x_mm' => 'decimal:2',
            'offset_y_mm' => 'decimal:2',
            'display_width_mm' => 'decimal:2',
            'rotation_deg' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
