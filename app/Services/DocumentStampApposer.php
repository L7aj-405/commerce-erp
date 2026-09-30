<?php

namespace App\Services;

use App\Models\DocumentStampApposition;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\DocumentStampBoundary;

/**
 * The one place that turns "apply the company stamp to this document" into a
 * DocumentStampApposition row. Shared by StampInvoiceAction and
 * StampQuotationAction (and any future StampXAction) so the freeze-the-
 * current-active-stamp logic exists exactly once. Document-type-specific
 * eligibility (status, policy) and audit logging stay in each caller — this
 * class only knows about "an organization" and "a stampable model".
 */
class DocumentStampApposer
{
    public function __construct(private readonly DocumentSellerProfile $sellerProfiles) {}

    /** @throws ValidationException */
    public function apply(Organization $organization, Model $stampable, int $actorUserId): DocumentStampApposition
    {
        $activeStamp = $organization->activeDocumentStamp()->first();
        if (! $activeStamp) {
            throw ValidationException::withMessages([
                'stamp' => "Aucun cachet configuré. Configurez le cachet de l'entreprise avant de l'apposer.",
            ]);
        }

        $presentation = $this->sellerProfiles->presentationSettings($organization);
        // Until the organization explicitly publishes Studio stamp settings,
        // preserve the existing active stamp version's dimensions/position.
        // This is the backward-compatible bridge for organizations configured
        // before PDF Studio became the presentation source of truth.
        $publishedStamp = data_get($organization->settings, 'document_profile.pdf_template.published.stamp');
        $style = is_array($publishedStamp) ? data_get($presentation, 'pdf_style.stamp', []) : [];

        return DB::transaction(function () use ($organization, $stampable, $activeStamp, $actorUserId, $style) {
            $alreadyStamped = DocumentStampApposition::query()
                ->where('stampable_type', $stampable->getMorphClass())
                ->where('stampable_id', $stampable->getKey())
                ->lockForUpdate()
                ->exists();
            if ($alreadyStamped) {
                throw ValidationException::withMessages(['stamp' => 'Ce document est déjà cacheté.']);
            }

            $apposition = new DocumentStampApposition;
            $apposition->organization_id = $organization->getKey();
            $apposition->stampable_type = $stampable->getMorphClass();
            $apposition->stampable_id = $stampable->getKey();
            $apposition->organization_document_stamp_id = $activeStamp->getKey();
            $apposition->image_path = $activeStamp->image_path;
            $apposition->mime_type = $activeStamp->mime_type;
            $apposition->position_anchor = $style['position_anchor'] ?? $activeStamp->position_anchor;
            $apposition->offset_x_mm = $style['offset_x_mm'] ?? $activeStamp->offset_x_mm;
            $apposition->offset_y_mm = $style['offset_y_mm'] ?? $activeStamp->offset_y_mm;
            $apposition->display_width_mm = $style['display_width_mm'] ?? $activeStamp->display_width_mm;
            $apposition->rotation_deg = $style['rotation_deg'] ?? $activeStamp->rotation_deg;
            $apposition->visible = (bool) ($style['visible'] ?? true);
            $apposition->display_height_mm = $style['display_height_mm'] ?? null;
            $apposition->opacity = (int) ($style['opacity'] ?? 100);
            $apposition->preserve_aspect_ratio = (bool) ($style['preserve_aspect_ratio'] ?? true);

            $naturalHeight = ($activeStamp->image_width && $activeStamp->image_height)
                ? (float) $apposition->display_width_mm * ($activeStamp->image_height / $activeStamp->image_width)
                : (float) $apposition->display_width_mm;
            $renderedHeight = $apposition->preserve_aspect_ratio || $apposition->display_height_mm === null
                ? $naturalHeight
                : (float) $apposition->display_height_mm;
            DocumentStampBoundary::assertFitsSafeArea(
                (float) $apposition->offset_x_mm,
                (float) $apposition->offset_y_mm,
                (float) $apposition->display_width_mm,
                $renderedHeight,
                (float) $apposition->rotation_deg,
            );
            $apposition->applied_by_user_id = $actorUserId;
            $apposition->applied_at = now();
            $apposition->save();

            return $apposition;
        });
    }
}
