<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\DeactivateOrganizationDocumentStampAction;
use App\Actions\Settings\SaveOrganizationDocumentStampAction;
use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\OrganizationDocumentStampPreviewService;
use App\Support\DocumentStampBoundary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OrganizationDocumentStampController extends Controller
{
    public function edit(Request $request, ActiveTenantContext $context): InertiaResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);

        $stamp = $organization->activeDocumentStamp()->first();

        return Inertia::render('Settings/DocumentStamp', [
            'organization' => $organization->only(['id', 'name']),
            'stamp' => $stamp ? [
                ...$stamp->only(['id', 'position_anchor', 'source']),
                'offset_x_mm' => (float) $stamp->offset_x_mm,
                'offset_y_mm' => (float) $stamp->offset_y_mm,
                'display_width_mm' => (float) $stamp->display_width_mm,
                'rotation_deg' => (float) $stamp->rotation_deg,
                'uploaded_at' => $stamp->created_at->toIso8601String(),
            ] : null,
            'bounds' => [
                'anchors' => DocumentStampBoundary::ANCHORS,
                'min_display_width_mm' => (float) config('documents.stamp.min_display_width_mm'),
                'max_display_width_mm' => (float) config('documents.stamp.max_display_width_mm'),
                'max_offset_x_mm' => (float) config('documents.stamp.max_offset_x_mm'),
                'max_offset_y_mm' => (float) config('documents.stamp.max_offset_y_mm'),
                'min_rotation_deg' => (float) config('documents.stamp.min_rotation_deg'),
                'max_rotation_deg' => (float) config('documents.stamp.max_rotation_deg'),
            ],
            'canUpdate' => $request->user()->hasPermission($organization, 'settings.update'),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveOrganizationDocumentStampAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $hasExisting = $organization->activeDocumentStamp()->exists();

        $data = $request->validate([
            'image' => [
                $hasExisting ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg,webp',
                'max:'.(int) config('documents.stamp.max_upload_kb'),
                'dimensions:min_width='.(int) config('documents.stamp.min_image_dimension').
                    ',min_height='.(int) config('documents.stamp.min_image_dimension').
                    ',max_width='.(int) config('documents.stamp.max_image_dimension').
                    ',max_height='.(int) config('documents.stamp.max_image_dimension'),
            ],
            'position_anchor' => ['required', Rule::in(DocumentStampBoundary::ANCHORS)],
            'offset_x_mm' => ['required', 'numeric', 'between:0,'.(float) config('documents.stamp.max_offset_x_mm')],
            'offset_y_mm' => ['required', 'numeric', 'between:0,'.(float) config('documents.stamp.max_offset_y_mm')],
            'display_width_mm' => [
                'required', 'numeric',
                'between:'.(float) config('documents.stamp.min_display_width_mm').','.(float) config('documents.stamp.max_display_width_mm'),
            ],
            'rotation_deg' => [
                'required', 'numeric',
                'between:'.(float) config('documents.stamp.min_rotation_deg').','.(float) config('documents.stamp.max_rotation_deg'),
            ],
        ]);

        // Per-field bounds above are just sanity caps on each raw value; the
        // real authority is DocumentStampBoundary::assertFitsSafeArea() inside
        // the action, which rejects any width/offset/rotation combination
        // that would actually overflow the printable content box.
        $action->execute($request->user(), $organization, $data, $request->file('image'));

        return back()->with('success', 'Cachet de l’entreprise enregistré.');
    }

    public function destroy(Request $request, ActiveTenantContext $context, DeactivateOrganizationDocumentStampAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $action->execute($request->user(), $organization);

        return back()->with('success', 'Cachet de l’entreprise désactivé.');
    }

    /**
     * Inline preview thumbnail for the settings page only — NOT a download
     * (no attachment disposition, no client-facing filename) and NOT a public
     * URL: it requires an authenticated session with `viewSettings` on this
     * organization, same as the settings page itself.
     */
    public function image(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);

        $stamp = $organization->activeDocumentStamp()->first();
        abort_unless($stamp, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($stamp->image_path), 404);

        return response($disk->get($stamp->image_path), 200, [
            'Content-Type' => $stamp->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request, ActiveTenantContext $context, OrganizationDocumentStampPreviewService $previewer): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);

        $stamp = $organization->activeDocumentStamp()->first();
        abort_unless($stamp, 404, 'Aucun cachet configuré.');

        $data = $request->validate([
            'position_anchor' => ['nullable', Rule::in(DocumentStampBoundary::ANCHORS)],
            'offset_x_mm' => ['nullable', 'numeric', 'between:0,'.(float) config('documents.stamp.max_offset_x_mm')],
            'offset_y_mm' => ['nullable', 'numeric', 'between:0,'.(float) config('documents.stamp.max_offset_y_mm')],
            'display_width_mm' => [
                'nullable', 'numeric',
                'between:'.(float) config('documents.stamp.min_display_width_mm').','.(float) config('documents.stamp.max_display_width_mm'),
            ],
            'rotation_deg' => [
                'nullable', 'numeric',
                'between:'.(float) config('documents.stamp.min_rotation_deg').','.(float) config('documents.stamp.max_rotation_deg'),
            ],
        ]);

        $offsetX = isset($data['offset_x_mm']) ? (float) $data['offset_x_mm'] : (float) $stamp->offset_x_mm;
        $offsetY = isset($data['offset_y_mm']) ? (float) $data['offset_y_mm'] : (float) $stamp->offset_y_mm;
        $displayWidth = isset($data['display_width_mm']) ? (float) $data['display_width_mm'] : (float) $stamp->display_width_mm;
        $rotation = isset($data['rotation_deg']) ? (float) $data['rotation_deg'] : (float) $stamp->rotation_deg;
        $displayHeight = ($stamp->image_width && $stamp->image_height)
            ? $displayWidth * ($stamp->image_height / $stamp->image_width)
            : $displayWidth;
        DocumentStampBoundary::assertFitsSafeArea($offsetX, $offsetY, $displayWidth, $displayHeight, $rotation);

        $document = $previewer->render(
            $organization,
            $stamp,
            $data['position_anchor'] ?? null,
            isset($data['offset_x_mm']) ? (float) $data['offset_x_mm'] : null,
            isset($data['offset_y_mm']) ? (float) $data['offset_y_mm'] : null,
            isset($data['display_width_mm']) ? (float) $data['display_width_mm'] : null,
            isset($data['rotation_deg']) ? (float) $data['rotation_deg'] : null,
        );

        return response($document['bytes'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'inline; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
