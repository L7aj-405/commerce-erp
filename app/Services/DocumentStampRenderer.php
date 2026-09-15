<?php

namespace App\Services;

use App\Models\DocumentStampApposition;
use App\Models\Invoice;
use App\Models\OrganizationDocumentStamp;
use App\Models\Quotation;
use App\Support\DocumentStampBoundary;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a stamp apposition (or, for the settings preview, a live
 * configuration) into the small, renderer-ready array the shared
 * `documents.partials.stamp` Blade partial expects: an embedded `data:` URI
 * image plus anchor/offset/width/rotation. This is the ONE place that reads
 * the private stamp file and computes safe-area-clamped positioning —
 * Invoice, Devis and any future document renderer call this instead of
 * duplicating the logic (see DocumentSellerProfile::embedLogo for the
 * identical pattern used for the Document Profile logo/seller snapshot).
 *
 * Renderers must call `forInvoice()`/`forQuotation()`, which read the
 * document's own frozen `DocumentStampApposition` row — never the
 * organization's current, mutable `OrganizationDocumentStamp` — so a stamped
 * document keeps rendering identically after the organization replaces its
 * active stamp.
 */
class DocumentStampRenderer
{
    /** @return array{image:string,anchor:string,offset_x_mm:float,offset_y_mm:float,display_width_mm:float,rotation_deg:float}|null */
    public function forInvoice(Invoice $invoice): ?array
    {
        return $this->fromApposition($invoice->stampApposition ?? $invoice->stampApposition()->first());
    }

    /** @return array{image:string,anchor:string,offset_x_mm:float,offset_y_mm:float,display_width_mm:float,rotation_deg:float}|null */
    public function forQuotation(Quotation $quotation): ?array
    {
        return $this->fromApposition($quotation->stampApposition ?? $quotation->stampApposition()->first());
    }

    /**
     * Live preview for the settings page: the organization's currently active
     * stamp image, with position/size/rotation overridden by (not-yet-saved)
     * form values so the user can see the effect of a change before saving.
     * Values are clamped exactly like a real save would clamp them.
     *
     * @return array{image:string,anchor:string,offset_x_mm:float,offset_y_mm:float,display_width_mm:float,rotation_deg:float}|null
     */
    public function preview(
        OrganizationDocumentStamp $stamp,
        ?string $anchor = null,
        ?float $offsetXMm = null,
        ?float $offsetYMm = null,
        ?float $displayWidthMm = null,
        ?float $rotationDeg = null,
    ): ?array {
        $image = $this->embed($stamp->image_path, $stamp->mime_type);
        if ($image === null) {
            return null;
        }

        return [
            'image' => $image,
            'anchor' => DocumentStampBoundary::normalizeAnchor($anchor ?? $stamp->position_anchor),
            'offset_x_mm' => DocumentStampBoundary::clampOffsetX($offsetXMm ?? (float) $stamp->offset_x_mm),
            'offset_y_mm' => DocumentStampBoundary::clampOffsetY($offsetYMm ?? (float) $stamp->offset_y_mm),
            'display_width_mm' => DocumentStampBoundary::clampDisplayWidth($displayWidthMm ?? (float) $stamp->display_width_mm),
            'rotation_deg' => DocumentStampBoundary::clampRotation($rotationDeg ?? (float) $stamp->rotation_deg),
        ];
    }

    /** @return array{image:string,anchor:string,offset_x_mm:float,offset_y_mm:float,display_width_mm:float,rotation_deg:float}|null */
    private function fromApposition(?DocumentStampApposition $apposition): ?array
    {
        if (! $apposition) {
            return null;
        }

        $image = $this->embed($apposition->image_path, $apposition->mime_type);
        if ($image === null) {
            return null;
        }

        return [
            'image' => $image,
            'anchor' => DocumentStampBoundary::normalizeAnchor($apposition->position_anchor),
            'offset_x_mm' => DocumentStampBoundary::clampOffsetX((float) $apposition->offset_x_mm),
            'offset_y_mm' => DocumentStampBoundary::clampOffsetY((float) $apposition->offset_y_mm),
            'display_width_mm' => DocumentStampBoundary::clampDisplayWidth((float) $apposition->display_width_mm),
            'rotation_deg' => DocumentStampBoundary::clampRotation((float) $apposition->rotation_deg),
        ];
    }

    private function embed(string $path, string $mimeType): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($disk->get($path));
    }
}
