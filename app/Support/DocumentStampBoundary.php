<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for what a "safe" stamp position/size/rotation is.
 * Used both when persisting a stamp configuration (so nothing out-of-range is
 * ever saved) and defensively when rendering (so a row saved under older,
 * looser bounds — or a preview using unsaved values — can never place the
 * stamp off the printable A4 content box). All measurements are millimetres,
 * relative to the printable content box the PDF templates already define via
 * `@page { margin: ... }` — see config('documents.stamp').
 */
class DocumentStampBoundary
{
    public const ANCHORS = ['bottom_left', 'bottom_right', 'top_left', 'top_right'];

    public static function clampOffsetX(float $value): float
    {
        return max(0.0, min($value, (float) config('documents.stamp.max_offset_x_mm')));
    }

    public static function clampOffsetY(float $value): float
    {
        return max(0.0, min($value, (float) config('documents.stamp.max_offset_y_mm')));
    }

    public static function clampDisplayWidth(float $value): float
    {
        return max(
            (float) config('documents.stamp.min_display_width_mm'),
            min($value, (float) config('documents.stamp.max_display_width_mm')),
        );
    }

    public static function clampRotation(float $value): float
    {
        return max(
            (float) config('documents.stamp.min_rotation_deg'),
            min($value, (float) config('documents.stamp.max_rotation_deg')),
        );
    }

    public static function normalizeAnchor(?string $anchor): string
    {
        return in_array($anchor, self::ANCHORS, true) ? $anchor : (string) config('documents.stamp.default_anchor');
    }

    /**
     * The axis-aligned bounding box a WxH rectangle occupies once rotated by
     * $rotationDeg around its own center — the standard rotated-rectangle
     * bounding-box formula, not full computational geometry.
     *
     * @return array{width: float, height: float}
     */
    public static function rotatedExtentMm(float $widthMm, float $heightMm, float $rotationDeg): array
    {
        $rad = deg2rad(abs($rotationDeg));

        return [
            'width' => abs($widthMm * cos($rad)) + abs($heightMm * sin($rad)),
            'height' => abs($widthMm * sin($rad)) + abs($heightMm * cos($rad)),
        ];
    }

    /**
     * Rejects a position/size/rotation combination that would push a
     * substantial part of the (rotated) stamp outside the printable content
     * box — a wide stamp at a generous offset, or a stamp rotated at an angle
     * near its offset limit, can occupy more room than the unrotated
     * width/height alone would suggest. The stamp's own center stays fixed at
     * the anchor offset (rotation never moves the anchor); only the painted
     * extent around that center grows.
     *
     * The near side (toward the anchor's own corner, e.g. the left/bottom
     * edge for `bottom_left`) is allowed to encroach by up to the safety
     * margin without being rejected — offset 0 plus a routine, modest
     * rotation (a few degrees, the normal case this feature exists for)
     * should not force the user to also bump the offset just to avoid an
     * error, and the @page margin itself (13-30mm, well beyond this
     * content-box-relative margin) is real, empty print area on that side.
     * The far side (toward the page's other edge, where actual content — the
     * totals table, running header text — lives) keeps the full margin as a
     * hard limit, since that is the side an overflow would actually clip
     * content or run off the page.
     *
     * @throws ValidationException
     */
    public static function assertFitsSafeArea(
        float $offsetXMm,
        float $offsetYMm,
        float $displayWidthMm,
        float $displayHeightMm,
        float $rotationDeg,
    ): void {
        $rotated = self::rotatedExtentMm($displayWidthMm, $displayHeightMm, $rotationDeg);
        $margin = (float) config('documents.stamp.safety_margin_mm');
        $maxX = (float) config('documents.stamp.content_box_width_mm') - $margin;
        $maxY = (float) config('documents.stamp.content_box_height_mm') - $margin;

        $growX = max(0.0, $rotated['width'] - $displayWidthMm) / 2;
        $growY = max(0.0, $rotated['height'] - $displayHeightMm) / 2;

        $nearX = $offsetXMm - $growX;
        $farX = $offsetXMm + $displayWidthMm + $growX;
        $nearY = $offsetYMm - $growY;
        $farY = $offsetYMm + $displayHeightMm + $growY;

        if ($nearX < -$margin || $farX > $maxX || $nearY < -$margin || $farY > $maxY) {
            throw ValidationException::withMessages([
                'display_width_mm' => 'Cette taille ne tient pas dans la zone imprimable à cette position. Réduisez la taille, le décalage ou l’inclinaison.',
            ]);
        }
    }
}
