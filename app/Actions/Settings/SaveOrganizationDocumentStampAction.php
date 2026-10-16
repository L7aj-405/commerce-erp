<?php

namespace App\Actions\Settings;

use App\Models\Organization;
use App\Models\OrganizationDocumentStamp;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStampStorage;
use App\Support\DocumentStampBoundary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a new organization stamp VERSION and makes it the active one.
 *
 * Deliberately never UPDATEs an existing row's image/position — every save
 * (a new image, or just an adjusted position/size) inserts a fresh row and
 * flips `active` off on the previous one. Stamp image FILES are never
 * deleted or overwritten either (a position-only save reuses the current
 * file's path unchanged). Both invariants together are what let a document's
 * frozen `DocumentStampApposition` keep rendering identically forever, even
 * after the organization replaces its stamp — see the migration docblock.
 */
class SaveOrganizationDocumentStampAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentStampStorage $storage,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data, ?UploadedFile $file = null): OrganizationDocumentStamp
    {
        $current = $organization->activeDocumentStamp()->first();

        if (! $file && (! $current || ! $this->storage->exists($current))) {
            throw ValidationException::withMessages(['image' => 'Une image de cachet est requise.']);
        }

        if ($file) {
            $dimensions = @getimagesize($file->getRealPath());
            $mimeType = (string) $file->getMimeType();
            $width = $dimensions[0] ?? null;
            $height = $dimensions[1] ?? null;
            $originalFilename = $file->getClientOriginalName();
        } else {
            $path = $current->image_path;
            $disk = $current->storage_disk;
            $mimeType = $current->mime_type;
            $width = $current->image_width;
            $height = $current->image_height;
            $originalFilename = $current->original_filename;
        }

        $anchor = DocumentStampBoundary::normalizeAnchor($data['position_anchor'] ?? $current?->position_anchor);
        $offsetX = DocumentStampBoundary::clampOffsetX(
            (float) ($data['offset_x_mm'] ?? $current?->offset_x_mm ?? config('documents.stamp.default_offset_x_mm')),
        );
        $offsetY = DocumentStampBoundary::clampOffsetY(
            (float) ($data['offset_y_mm'] ?? $current?->offset_y_mm ?? config('documents.stamp.default_offset_y_mm')),
        );
        $displayWidth = DocumentStampBoundary::clampDisplayWidth(
            (float) ($data['display_width_mm'] ?? $current?->display_width_mm ?? config('documents.stamp.default_display_width_mm')),
        );
        $rotation = DocumentStampBoundary::clampRotation(
            (float) ($data['rotation_deg'] ?? $current?->rotation_deg ?? config('documents.stamp.default_rotation_deg')),
        );

        // Rendered height from the image's own aspect ratio (falls back to a
        // square estimate when dimensions are unknown), so the safe-area
        // check reasons about the actual painted box, not just the width.
        $displayHeight = ($width && $height) ? $displayWidth * ($height / $width) : $displayWidth;
        DocumentStampBoundary::assertFitsSafeArea($offsetX, $offsetY, $displayWidth, $displayHeight, $rotation);

        $storedUpload = null;
        if ($file) {
            $storedUpload = $this->storage->store($file, $organization);
            if ($storedUpload === null) {
                throw ValidationException::withMessages(['image' => "Impossible d'enregistrer le fichier du cachet."]);
            }
            $path = $storedUpload['path'];
            $disk = $storedUpload['disk'];
        }

        try {
            return DB::transaction(function () use (
                $actor, $organization, $file, $current, $path, $disk, $mimeType, $width, $height,
                $originalFilename, $anchor, $offsetX, $offsetY, $displayWidth, $rotation,
            ) {
                $stamp = new OrganizationDocumentStamp;
                $stamp->organization_id = $organization->getKey();
                $stamp->image_path = $path;
                $stamp->storage_disk = $disk;
                $stamp->original_filename = $originalFilename;
                $stamp->mime_type = $mimeType;
                $stamp->image_width = $width;
                $stamp->image_height = $height;
                $stamp->position_anchor = $anchor;
                $stamp->offset_x_mm = $offsetX;
                $stamp->offset_y_mm = $offsetY;
                $stamp->display_width_mm = $displayWidth;
                $stamp->rotation_deg = $rotation;
                $stamp->source = 'uploaded';
                $stamp->active = true;
                $stamp->created_by_user_id = $actor->getKey();
                $stamp->save();

            // $current is guarded ($guarded = ['*']) like every other model in
            // this domain, so a plain ->update([...]) mass-assignment call
            // throws MassAssignmentException — forceFill() is this project's
            // established way to make a deliberate, targeted write to a
            // guarded model (see TestWooCommerceConnectionAction,
            // SendOrganizationMailTestAction for the same pattern).
                $current?->forceFill(['active' => false])->save();

                $this->audit->record(
                    $file ? ($current ? 'organization_document_stamp.replaced' : 'organization_document_stamp.uploaded') : 'organization_document_stamp.position_updated',
                    $actor, $organization, auditable: $stamp,
                    newValues: [
                        'position_anchor' => $stamp->position_anchor,
                        'offset_x_mm' => (string) $stamp->offset_x_mm,
                        'offset_y_mm' => (string) $stamp->offset_y_mm,
                        'display_width_mm' => (string) $stamp->display_width_mm,
                        'rotation_deg' => (string) $stamp->rotation_deg,
                        'image_replaced' => (bool) $file,
                    ],
                );

                return $stamp;
            });
        } catch (Throwable $exception) {
            if ($storedUpload !== null) {
                $this->storage->removeOwnedUpload($storedUpload['disk'], $storedUpload['path'], $organization);
            }

            throw $exception;
        }
    }
}
