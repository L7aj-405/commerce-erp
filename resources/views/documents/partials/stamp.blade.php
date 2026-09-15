{{--
    Reusable company-stamp overlay. Included once, right after the closing/
    totals section, in every document template that supports stamping —
    never copy this positioning math into an individual template.

    $stamp is the renderer-ready array from DocumentStampRenderer (null when
    the document has no apposition — nothing renders).

    Deliberately `position: absolute`, NOT `position: fixed`. In this Dompdf
    setup, `position: fixed` elements repeat on every page (see .watermark-logo
    / .runhead in the same templates); `position: absolute` places the element
    once, on whichever page it falls on in the normal document flow — which,
    included right after `.closing`, is the same page the totals landed on.
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
        $edge = match ($stamp['anchor']) {
            'bottom_right' => 'bottom: '.$stamp['offset_y_mm'].'mm; right: '.$stamp['offset_x_mm'].'mm;',
            'top_left' => 'top: '.$stamp['offset_y_mm'].'mm; left: '.$stamp['offset_x_mm'].'mm;',
            'top_right' => 'top: '.$stamp['offset_y_mm'].'mm; right: '.$stamp['offset_x_mm'].'mm;',
            default => 'bottom: '.$stamp['offset_y_mm'].'mm; left: '.$stamp['offset_x_mm'].'mm;', // bottom_left
        };
        $rotation = $stamp['rotation_deg'] ?? 0;
    @endphp
    <div style="position: absolute; {{ $edge }} z-index: 4;">
        <img
            src="{{ $stamp['image'] }}"
            alt=""
            style="width: {{ $stamp['display_width_mm'] }}mm; height: auto; display: block; transform: rotate({{ $rotation }}deg); transform-origin: center;"
        >
    </div>
@endif
