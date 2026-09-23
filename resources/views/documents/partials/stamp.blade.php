{{--
    Reusable company-stamp overlay. Never copy this positioning math into an
    individual template.

    $stamp is the renderer-ready array from DocumentStampRenderer (null when
    the document has no apposition — nothing renders).

    Default mode is `position: absolute`, which places the stamp once on the
    page where the template includes it. Invoice PDFs may pass
    `$repeatEveryPage = true`; that switches to `position: fixed`, the same
    Dompdf mechanism already used by running headers/footers/watermarks, so
    the immutable apposition repeats on every rendered page without creating
    a new stamp or mutating the document.

    Coordinates are relative to the page's content box (the @page margin
    already keeps 0,0 off the physical edge), matching how .runhead/.runfoot
    already use negative offsets to reach into the margin in these templates.

    Rotation is applied via CSS `transform: rotate()` on the <img> itself —
    Dompdf (3.x) supports `transform`/`transform-origin` (see
    Dompdf\Css\Style::_compute_transform). `transform-origin: center` (the
    CSS default, set explicitly here for clarity) rotates the image around
    its own center without moving the box `position: absolute` placed — the
    anchor point never shifts, only the painted stamp turns around it.
--}}
@if ($stamp)
    @php
        $position = ($repeatEveryPage ?? false) ? 'fixed' : 'absolute';
        $class = ($repeatEveryPage ?? false)
            ? 'document-stamp-apposition document-stamp-repeat'
            : 'document-stamp-apposition';
        $edge = match ($stamp['anchor']) {
            'bottom_right' => 'bottom: '.$stamp['offset_y_mm'].'mm; right: '.$stamp['offset_x_mm'].'mm;',
            'top_left' => 'top: '.$stamp['offset_y_mm'].'mm; left: '.$stamp['offset_x_mm'].'mm;',
            'top_right' => 'top: '.$stamp['offset_y_mm'].'mm; right: '.$stamp['offset_x_mm'].'mm;',
            default => 'bottom: '.$stamp['offset_y_mm'].'mm; left: '.$stamp['offset_x_mm'].'mm;', // bottom_left
        };
        $rotation = $stamp['rotation_deg'] ?? 0;
    @endphp
    <div class="{{ $class }}" style="position: {{ $position }}; {{ $edge }} z-index: 0;">
        <img
            class="document-stamp-image"
            src="{{ $stamp['image'] }}"
            alt=""
            style="width: {{ $stamp['display_width_mm'] }}mm; height: auto; display: block; transform: rotate({{ $rotation }}deg); transform-origin: center;"
        >
    </div>
@endif
