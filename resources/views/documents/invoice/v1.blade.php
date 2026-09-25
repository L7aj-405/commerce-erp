@php
    $locale = config('documents.locale');
    $t = fn (string $key) => __('documents.'.$key, locale: $locale);
    $accent = $seller['accent_color'] ?? \App\Services\DocumentSellerProfile::DEFAULT_ACCENT_COLOR;
    $ink = '#1f211d';
    $currency = $document['currency'];
    $showRemise = $has_discount;
    $numberLabel = $document['number'] ?: ($watermark ?: '—');
    $buyerLabel = $buyer['company'] ?: ($buyer['name'] ?: '—');
    $displayWebsite = preg_replace('#^https?://#i', '', trim((string) ($seller['website'] ?? '')));
    $displayWebsite = rtrim($displayWebsite, '/');

    // Professional ruled-register rhythm: the Articles table visually fills
    // the remaining item area with empty, data-free rows instead of a blank
    // white spacer. The estimate is based on "visual row units", not raw item
    // count, so long wrapped descriptions consume more of the page budget and
    // automatically reduce decorative rows. These rows are template-only and
    // never exist in invoice data, snapshots, exports, totals, tax, or finance.
    $visualRowUnits = collect($lines)->reduce(function (int $carry, array $line): int {
        $text = trim(($line['description'] ?? '').' '.($line['variant'] ?? ''));
        $wrappedUnits = max(1, (int) ceil(mb_strlen($text) / 58));

        return $carry + min(3, $wrappedUnits);
    }, 0);
    $notesUnits = ! empty($document['notes']) ? 2 : 0;
    $usedUnits = max(1, $visualRowUnits + $notesUnits);
    // Keep a real reserve for the closing block (totals + amount in words).
    // Decorative rows must never consume that reserve and push a short
    // invoice's closing block onto a second page.
    $firstPageTargetUnits = 7;
    $continuationPageTargetUnits = 18;

    if ($usedUnits <= $firstPageTargetUnits) {
        $emptyRows = $firstPageTargetUnits - $usedUnits;
    } else {
        $finalPageUnits = ($usedUnits - $firstPageTargetUnits) % $continuationPageTargetUnits;
        $emptyRows = $finalPageUnits === 0 ? 0 : ($continuationPageTargetUnits - $finalPageUnits);
    }

    // Cap the purely decorative fill so an awkward edge case cannot create a
    // new mostly-empty trailing page. Real item rows and the kept-together
    // totals block always take priority over these presentation rows.
    // Six ruled rows (at 6mm each) give the register-style visual fill while
    // preserving enough room for the complete closing block on ordinary
    // one-page invoices. Real content always wins over decorative fill.
    $emptyRows = min(6, max(0, $emptyRows));

    // Item-table column widths (percentages, each variant sums to 100).
    $cols = $showRemise
        ? ['ref' => 10, 'des' => 33, 'pres' => 9, 'qty' => 6, 'pu' => 11, 'pt' => 11, 'rem' => 9, 'ttc' => 11]
        : ['ref' => 11, 'des' => 37, 'pres' => 10, 'qty' => 7, 'pu' => 12, 'pt' => 12, 'ttc' => 11];

    // Footer legal fragments (from the immutable seller snapshot only).
    $legalBits = collect([
        ($seller['registration_number'] ?? null) ? 'RC N° : '.$seller['registration_number'] : null,
        ($seller['patente_number'] ?? null) ? 'TP : '.$seller['patente_number'] : null,
        ($seller['tax_identifier'] ?? null) ? 'ICE : '.$seller['tax_identifier'] : null,
    ]);
    foreach (($seller['additional_identifiers'] ?? []) as $identifier) {
        $legalBits->push($identifier['label'].' : '.$identifier['value']);
    }
    $footerLine1 = trim(collect([
        $seller['address'] ?? null,
        ($seller['phone'] ?? null) ? 'Tél : '.$seller['phone'] : null,
    ])->filter()->implode('   '));
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $document['number'] }}</title>
    <style>
        /* --- A4 geometry: top band = running identity, bottom band = legal footer + page number --- */
        @page { margin: 20mm 13mm 30mm; }
        * { box-sizing: border-box; }
        body { color: {{ $ink }}; font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; line-height: 1.43; }

        /* Running compact identity strip — sits in the top page margin on EVERY page. */
        .runhead {
            position: fixed;
            top: -15mm; left: 0; right: 0;
            height: 12mm;
            border-bottom: 1px solid #d7d7cf;
            font-size: 8.5px;
            color: #3f423b;
        }
        .runhead .r1 { display: block; }
        .runhead .r1 .who { font-weight: bold; color: {{ $ink }}; text-transform: uppercase; }
        .runhead .r1 .doc { float: right; font-weight: bold; color: {{ $accent }}; }
        .runhead .r2 { display: block; margin-top: 2px; }
        .runhead .r2 .sep { color: #bbb; }

        /* Legal / banking footer — repeats in the bottom page margin on EVERY page. */
        .runfoot {
            position: fixed;
            bottom: -21mm; left: 0; right: 0;
            border-top: 1px solid #d7d7cf;
            padding-top: 4px;
            font-size: 10px;
            font-style: italic;
            color: #4f524a;
            text-align: center;
            line-height: 1.45;
        }
        /* Draft / cancelled state marker. */
        .watermark-text {
            position: fixed;
            top: 40%; left: 12%;
            font-size: 90px; font-weight: bold;
            color: rgba(176, 69, 59, .12);
            transform: rotate(-26deg);
        }
        /* Issued invoices: the seller's own logo (from the immutable snapshot),
           very faint, centred in the free mid-body area, behind all content and
           out of normal flow so it shifts nothing. Repeats on every page. */
        .watermark-logo {
            position: fixed;
            top: 0; left: 0; right: 0;
            text-align: center;
            z-index: -1;
        }
        .watermark-logo img {
            width: 95mm;
            margin-top: 118mm;
            opacity: 0.05;
        }

        /* --- Page 1 masthead (normal flow, page 1 only by nature) --- */
        .masthead { width: 100%; border-collapse: collapse; }
        .masthead td { vertical-align: top; }
        .logo { max-height: 100px; max-width: 300px; margin-bottom: 16px; min-width: 300px; }
        .company-name { font-size: 17px; font-weight: bold; text-transform: uppercase; color: {{ $ink }}; margin-bottom: 7px; }
        .seller { color: #343730; font-size: 9.2px; line-height: 1.42; }
        .seller-row, .dest-row { margin-bottom: 2px; }
        .info-label { font-weight: 600; color: #4c4f47; }
        .info-value { font-weight: 700; color: {{ $ink }}; }
        .dest { font-size: 9.2px; line-height: 1.42; color: #343730; }
        .dest .lbl { font-weight: bold; letter-spacing: .08em; font-size: 8.8px; color: #3d4039; }
        .dest .name { font-weight: bold; color: {{ $accent }}; font-size: 10.8px; margin: 3px 0 2px; }
        .dest-details { margin-top: 6px; }

        h1.title {
            margin: 18px 0 12px;
            text-align: center;
            font-size: 26px; font-weight: bold; letter-spacing: .06em;
            color: {{ $ink }};
        }

        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.meta th {
            border: 1px solid #c9c9c2; padding: 5px 6px;
            font-size: 8.6px; font-weight: bold; text-align: center; color: #2f322d;
        }
        table.meta td { border: 1px solid #c9c9c2; padding: 6px; text-align: center; font-size: 9.4px; font-weight: 600; color: {{ $ink }}; }

        /* --- Items: grows naturally, header repeats, rows never split. --- */
        table.items { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
        table.items thead { display: table-header-group; }
        table.items tr { page-break-inside: avoid; }
        table.items th {
            background: {{ $accent }}; color: #fff;
            font-size: 7.9px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em;
            padding: 6px 5px;
        }
        table.items td {
            /* Compact but readable A4 row rhythm. Medium-length commercial
               descriptions commonly wrap to two lines; the old 5px vertical
               padding plus the document-wide 1.42 line-height made the rows,
               rather than the adaptive spacer, force ordinary invoices onto
               a second page. This applies uniformly at every line count. */
            border-bottom: 1px solid #e2e2da; padding: 3px 5px;
            font-size: 8.8px; line-height: 1.3;
            vertical-align: top; word-wrap: break-word; overflow-wrap: break-word;
        }
        .num { text-align: right; white-space: nowrap; }
        .ctr { text-align: center; }
        .des-sub { color: #555950; font-size: 8.2px; }
        table.items tr.empty-ruled-row td {
            height: 6mm;
            padding-top: 0;
            padding-bottom: 0;
            color: transparent;
        }

        .notes { margin-top: 12px; white-space: pre-line; page-break-inside: avoid; }

        /* --- Closing section: totals + amount in words + issuer line, one
               compact block that never drifts apart (kept together even
               across a page break). Centred across the full page width
               regardless of the stamp — the stamp is an independent overlay
               and may cross it. --- */
        .closing { page-break-inside: avoid; }
        .closing-inner { width: 100%; border-collapse: collapse; }
        .closing-inner td { vertical-align: top; }
        table.totals { border-collapse: collapse; width: 100%; }
        table.totals td { padding: 3px 4px; }
        table.totals .lbl { text-align: right; padding-right: 16px; color: #333; }
        table.totals .val { text-align: right; white-space: nowrap; }
        table.totals tr.strong td { font-weight: bold; }
        table.totals tr.grand td { border-top: 1px solid {{ $ink }}; font-weight: bold; font-size: 11px; }
        .words { margin-top: 12px; text-align: center; }
        .words-rule { border-top: 1px solid #c9c9c2; width: 60%; margin: 0 auto 8px; }
        .words-intro { font-weight: bold; font-size: 9px; }
        .words-value { margin-top: 6px; font-style: italic; font-weight: bold; font-size: 12px; text-transform: uppercase; }
        .issued-meta { margin: 12px 0 0; color: #888; font-size: 8px; text-align: left; }
        @include('documents.partials.commercial-style', ['accent' => $accent, 'ink' => $ink])
    </style>
</head>
<body>

@if ($watermark)
    <div class="watermark-text">{{ $watermark }}</div>
@elseif (($seller['show_invoice_watermark'] ?? false) && ($seller['logo'] ?? null))
    <div class="watermark-logo"><img src="{{ $seller['logo'] }}" alt=""></div>
@endif

@include('documents.partials.stamp', ['stamp' => $stamp ?? null, 'repeatEveryPage' => true])

{{-- Running identity strip (every page, incl. page 1, sitting in the top margin) --}}
<div class="runhead">
    <span class="r1">
        <span class="who">{{ $seller['legal_name'] }}</span>
        <span class="doc">{{ $title }} {{ $numberLabel }} · V{{ $document['version'] }}</span>
    </span>
    <span class="r2">
        {{ $t('customer') }} : {{ $buyerLabel }}
        <span class="sep">·</span>
        {{ $t('date') }} : {{ $document['date'] }}
    </span>
</div>

{{-- Legal / banking footer (every page, sitting in the bottom margin) --}}
<div class="runfoot">
    @if ($footerLine1){{ $footerLine1 }}<br>@endif
    @if ($seller['email'] ?? null)Email : {{ $seller['email'] }}<br>@endif
    @if ($legalBits->isNotEmpty()){{ $legalBits->implode(' ; ') }}<br>@endif
    @if (data_get($seller, 'bank.rib'))RIB {{ data_get($seller, 'bank.name') ? data_get($seller, 'bank.name').' : ' : '' }}{{ data_get($seller, 'bank.rib') }}@endif
    @if ($seller['footer_text'] ?? null)<br>{{ $seller['footer_text'] }}@endif
</div>

{{-- ===== Page 1 masthead ===== --}}
<table class="masthead">
    <tr>
        <td style="width: 55%; padding-right: 28px;">
            @if ($seller['logo'] ?? null)
                <img class="logo" src="{{ $seller['logo'] }}" alt="">
            @else
                <div class="company-name">{{ $seller['legal_name'] }}</div>
            @endif
            <div class="seller">
                @if (($seller['trade_name'] ?? null) && ($seller['legal_name'] ?? null))<div class="seller-row"><span class="info-value">{{ $seller['legal_name'] }}</span></div>@endif
                @if ($seller['address'] ?? null)<div class="seller-row"><span class="info-label">Adresse :</span> <span class="info-value">{{ $seller['address'] }}</span></div>@endif
                @if ($seller['tax_identifier'] ?? null)<div class="seller-row"><span class="info-label">ICE :</span> <span class="info-value">{{ $seller['tax_identifier'] }}</span></div>@endif
                @if ($seller['phone'] ?? null)<div class="seller-row"><span class="info-label">Tél :</span> <span class="info-value">{{ $seller['phone'] }}</span></div>@endif
                @if ($seller['email'] ?? null)<div class="seller-row"><span class="info-label">Email :</span> <span class="info-value">{{ $seller['email'] }}</span></div>@endif
                @if ($seller['registration_number'] ?? null)<div class="seller-row"><span class="info-label">RC :</span> <span class="info-value">{{ $seller['registration_number'] }}</span></div>@endif
                @if ($seller['patente_number'] ?? null)<div class="seller-row"><span class="info-label">TP :</span> <span class="info-value">{{ $seller['patente_number'] }}</span></div>@endif
                @if ($displayWebsite !== '')<div class="seller-row"><span class="info-label">Web :</span> <span class="info-value">{{ $displayWebsite }}</span></div>@endif
                @foreach (($seller['additional_identifiers'] ?? []) as $identifier)
                    <div class="seller-row"><span class="info-label">{{ $identifier['label'] }} :</span> <span class="info-value">{{ $identifier['value'] }}</span></div>
                @endforeach
            </div>
        </td>
        <td style="width: 45%; padding-left: 12px; padding-top: 68px;">
            <div class="dest">
                <div class="lbl">{{ $t('recipient') }}</div>
                <div class="name">{{ $buyerLabel }}</div>
                <div class="dest-details">
                @if ($buyer['company'] && $buyer['name'])<div class="dest-row"><span class="info-value">{{ $buyer['name'] }}</span></div>@endif
                <div class="dest-row"><span class="info-label">Adresse :</span> <span class="info-value">{{ $buyer['address'] ?? '' }}</span></div>
                <div class="dest-row"><span class="info-label">Tél :</span> <span class="info-value">{{ $buyer['phone'] ?? '' }}</span></div>
                <div class="dest-row"><span class="info-label">ICE :</span> <span class="info-value">{{ $buyer['tax_identifier'] ?? '' }}</span></div>
                </div>
            </div>
        </td>
    </tr>
</table>

<h1 class="title">
    {{ $title }}
</h1>

<table class="meta">
    <thead>
        <tr>
            <th style="width: 20%;">{{ $t('invoice_number') }}</th>
            <th style="width: 28%;">{{ $t('representative') }}</th>
            <th style="width: 32%;">{{ $t('payment_method') }}</th>
            <th style="width: 20%;">{{ $t('invoice_date') }} :</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $numberLabel }}</td>
            <td>{{ $document['representative'] ?: '—' }}</td>
            <td>{{ $document['payment_method'] ?: '—' }}</td>
            <td>{{ $document['date'] }}</td>
        </tr>
    </tbody>
</table>

{{-- ===== Items ===== --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: {{ $cols['ref'] }}%; text-align: left;">{{ $t('reference') }}</th>
            <th style="width: {{ $cols['des'] }}%; text-align: left;">{{ $t('designation') }}</th>
            <th style="width: {{ $cols['pres'] }}%;">{{ $t('presentation') }}</th>
            <th style="width: {{ $cols['qty'] }}%;">{{ $t('quantity') }}</th>
            <th style="width: {{ $cols['pu'] }}%;" class="num">{{ $t('unit_price_ht') }}</th>
            <th style="width: {{ $cols['pt'] }}%;" class="num">{{ $t('line_total_ht') }}</th>
            @if ($showRemise)
                <th style="width: {{ $cols['rem'] }}%;" class="num">{{ $t('line_discount') }}</th>
            @endif
            <th style="width: {{ $cols['ttc'] }}%;" class="num">{{ $t('total_ttc') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ $line['reference'] ?: '' }}</td>
                <td>
                    {{ $line['description'] }}
                    @if ($line['variant'])<div class="des-sub">{{ $line['variant'] }}</div>@endif
                </td>
                <td class="ctr">{{ $line['presentation'] }}</td>
                <td class="ctr">{{ $line['quantity'] }}</td>
                <td class="num">{{ $line['unit_price_ht'] }}</td>
                <td class="num">{{ $line['line_total_ht'] }}</td>
                @if ($showRemise)
                    <td class="num">{{ $line['has_discount'] ? $line['discount'] : '' }}</td>
                @endif
                <td class="num">{{ $line['total'] }}</td>
            </tr>
        @endforeach
        @for ($i = 0; $i < $emptyRows; $i++)
            <tr class="empty-ruled-row" aria-hidden="true">
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td class="ctr">&nbsp;</td>
                <td class="ctr">&nbsp;</td>
                <td class="num">&nbsp;</td>
                <td class="num">&nbsp;</td>
                @if ($showRemise)
                    <td class="num">&nbsp;</td>
                @endif
                <td class="num">&nbsp;</td>
            </tr>
        @endfor
    </tbody>
</table>

@if ($document['notes'])
    <div class="notes"><strong>{{ $t('notes') }}</strong><br>{{ $document['notes'] }}</div>
@endif

{{-- ===== Closing section: totals + amount in words + issuer line, kept together ===== --}}
<div class="closing">
    <table class="closing-inner">
        <tr>
            <td style="width: 55%;">&nbsp;</td>
            <td style="width: 45%;">
                <table class="totals">
                    @if ($has_discount)
                        <tr><td class="lbl">{{ $t('subtotal') }}</td><td class="val">{{ $totals['subtotal'] }} {{ $currency }}</td></tr>
                        <tr class="strong"><td class="lbl">{{ $t('total_ht') }}</td><td class="val">{{ $totals['net'] }} {{ $currency }}</td></tr>
                    @else
                        <tr><td class="lbl">{{ $t('total_ht') }}</td><td class="val">{{ $totals['subtotal'] }} {{ $currency }}</td></tr>
                    @endif
                    @foreach ($tax_lines as $taxLine)
                        <tr><td class="lbl">{{ $taxLine['label'] }} ({{ $taxLine['rate'] }})</td><td class="val">{{ $taxLine['amount'] }} {{ $currency }}</td></tr>
                    @endforeach
                    @if ($has_discount)
                        <tr><td class="lbl">{{ $t('discount') }}</td><td class="val">- {{ $totals['discount'] }} {{ $currency }}</td></tr>
                    @endif
                    <tr class="grand"><td class="lbl">{{ $t('total_ttc') }}</td><td class="val">{{ $totals['total'] }} {{ $currency }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="words">
        <div class="words-rule"></div>
        <div class="words-intro">{{ $t('amount_in_words_intro') }}</div>
        <div class="words-value">{{ $amount_in_words }}</div>
    </div>

</div>

</body>
</html>
