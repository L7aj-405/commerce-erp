@php
    $locale = config('documents.locale');
    $t = fn (string $key) => __('documents.'.$key, locale: $locale);
    $accent = $seller['accent_color'] ?? \App\Services\DocumentSellerProfile::DEFAULT_ACCENT_COLOR;
    $ink = '#1f211d';
    $currency = $document['currency'];
    $showRemise = $has_discount;
    $numberLabel = $document['number'] ?: ($watermark ?: '—');
    $buyerLabel = $buyer['company'] ?: ($buyer['name'] ?: '—');

    $closingMm = ($has_discount ? 62 : 46) + (! empty($metadata['issued_at']) ? 8 : 0);
    $notesMm = (! empty($document['notes']) ? 20 : 0) + (! empty($document['terms']) ? 20 : 0);
    $bodyFloor = max(24, 240 - 78 - $closingMm - $notesMm);

    $cols = $showRemise
        ? ['ref' => 10, 'des' => 33, 'pres' => 9, 'qty' => 6, 'pu' => 11, 'pt' => 11, 'rem' => 9, 'ttc' => 11]
        : ['ref' => 11, 'des' => 37, 'pres' => 10, 'qty' => 7, 'pu' => 12, 'pt' => 12, 'ttc' => 11];

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
        @page { margin: 20mm 13mm 30mm; }
        * { box-sizing: border-box; }
        body { color: {{ $ink }}; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.42; }
        .runhead { position: fixed; top: -15mm; left: 0; right: 0; height: 12mm; border-bottom: 1px solid #d7d7cf; font-size: 8px; color: #555; }
        .runhead .r1 { display: block; }
        .runhead .r1 .who { font-weight: bold; color: {{ $ink }}; text-transform: uppercase; }
        .runhead .r1 .doc { float: right; font-weight: bold; color: {{ $accent }}; }
        .runhead .r2 { display: block; margin-top: 2px; }
        .runhead .r2 .sep { color: #bbb; }
        .runfoot { position: fixed; bottom: -21mm; left: 0; right: 0; border-top: 1px solid #d7d7cf; padding-top: 4px; font-size: 7px; font-style: italic; color: #666; text-align: center; line-height: 1.45; }
        .watermark-text { position: fixed; top: 40%; left: 12%; font-size: 90px; font-weight: bold; color: rgba(176, 69, 59, .12); transform: rotate(-26deg); }
        .watermark-logo { position: fixed; top: 0; left: 0; right: 0; text-align: center; z-index: -1; }
        .watermark-logo img { width: 95mm; margin-top: 118mm; opacity: 0.05; }
        .masthead { width: 100%; border-collapse: collapse; }
        .masthead td { vertical-align: top; }
        .logo { max-height: 60px; max-width: 220px; margin-bottom: 8px; }
        .seller { color: #444; font-size: 8.5px; }
        .dest { font-size: 8.5px; }
        .dest .lbl { font-weight: bold; letter-spacing: .08em; }
        .dest .name { font-weight: bold; color: {{ $accent }}; font-size: 10px; }
        h1.title { margin: 18px 0 12px; text-align: center; font-size: 26px; font-weight: bold; letter-spacing: .06em; color: {{ $ink }}; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.meta th { border: 1px solid #c9c9c2; padding: 5px 6px; font-size: 8px; font-weight: normal; text-align: center; color: #555; }
        table.meta td { border: 1px solid #c9c9c2; padding: 6px; text-align: center; }
        .items-wrap { min-height: {{ $bodyFloor }}mm; }
        table.items { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
        table.items thead { display: table-header-group; }
        table.items tr { page-break-inside: avoid; }
        table.items th { background: {{ $accent }}; color: #fff; font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; padding: 6px 5px; }
        table.items td { border-bottom: 1px solid #e2e2da; padding: 5px 5px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        .num { text-align: right; white-space: nowrap; }
        .ctr { text-align: center; }
        .des-sub { color: #777; font-size: 8px; }
        .notes { margin-top: 12px; white-space: pre-line; page-break-inside: avoid; }
        .closing { margin-top: 12px; page-break-inside: avoid; }
        .closing-inner { width: 100%; border-collapse: collapse; }
        .closing-inner td { vertical-align: top; }
        table.totals { border-collapse: collapse; width: 100%; }
        table.totals td { padding: 3px 4px; }
        table.totals .lbl { text-align: right; padding-right: 16px; color: #333; }
        table.totals .val { text-align: right; white-space: nowrap; }
        table.totals tr.strong td { font-weight: bold; }
        table.totals tr.grand td { border-top: 1px solid {{ $ink }}; font-weight: bold; font-size: 11px; }
        .words { margin-top: 18px; text-align: center; }
        .words-rule { border-top: 1px solid #c9c9c2; width: 60%; margin: 0 auto 8px; }
        .words-intro { font-weight: bold; font-size: 9px; }
        .words-value { margin-top: 6px; font-style: italic; font-weight: bold; font-size: 12px; text-transform: uppercase; }
        .issued-meta { margin-top: 14px; color: #888; font-size: 8px; }
    </style>
</head>
<body>

@if ($watermark)
    <div class="watermark-text">{{ $watermark }}</div>
@elseif ($seller['logo'] ?? null)
    <div class="watermark-logo"><img src="{{ $seller['logo'] }}" alt=""></div>
@endif

<div class="runhead">
    <span class="r1">
        <span class="who">{{ $seller['legal_name'] }}</span>
        <span class="doc">{{ $title }} {{ $numberLabel }}</span>
    </span>
    <span class="r2">
        {{ $t('customer') }} : {{ $buyerLabel }}
        <span class="sep">·</span>
        {{ $t('quotation_date') }} : {{ $document['date'] }}
    </span>
</div>

<div class="runfoot">
    @if ($footerLine1){{ $footerLine1 }}<br>@endif
    @if ($seller['email'] ?? null)Email : {{ $seller['email'] }}<br>@endif
    @if ($legalBits->isNotEmpty()){{ $legalBits->implode(' ; ') }}<br>@endif
    @if (data_get($seller, 'bank.rib'))RIB {{ data_get($seller, 'bank.name') ? data_get($seller, 'bank.name').' : ' : '' }}{{ data_get($seller, 'bank.rib') }}@endif
    @if ($seller['footer_text'] ?? null)<br>{{ $seller['footer_text'] }}@endif
</div>

<table class="masthead">
    <tr>
        <td style="width: 60%; padding-right: 16px;">
            @if ($seller['logo'] ?? null)
                <img class="logo" src="{{ $seller['logo'] }}" alt="">
            @else
                <div style="font-size: 15px; font-weight: bold; text-transform: uppercase; margin-bottom: 8px;">{{ $seller['legal_name'] }}</div>
            @endif
            <div class="seller">
                @if ($seller['address'] ?? null){{ $seller['address'] }}<br>@endif
                @if ($seller['tax_identifier'] ?? null)ICE : {{ $seller['tax_identifier'] }}<br>@endif
                @if ($seller['phone'] ?? null)Tél : {{ $seller['phone'] }}<br>@endif
                FAX : {{ $seller['fax'] ?? '' }}<br>
                @if ($seller['email'] ?? null)Mail : {{ $seller['email'] }}@endif
            </div>
        </td>
        <td style="width: 40%;">
            <div class="dest">
                <div class="lbl">{{ $t('recipient') }}</div>
                <div class="name">{{ $buyerLabel }}</div>
                @if ($buyer['company'] && $buyer['name'])<div>{{ $buyer['name'] }}</div>@endif
                <div>Adresse : {{ $buyer['address'] ?? '' }}</div>
                <div>Tél : {{ $buyer['phone'] ?? '' }}</div>
                <div>ICE : {{ $buyer['tax_identifier'] ?? '' }}</div>
            </div>
        </td>
    </tr>
</table>

<h1 class="title">{{ $title }}</h1>
@if (! empty($document['revision']))
    <div style="text-align: center; margin: -6px 0 12px; font-size: 11px; font-weight: bold; color: {{ $accent }};">
        {{ $document['revision'] }}@if (! empty($document['root_number'])) — {{ $title }} {{ $document['root_number'] }}@endif
    </div>
@endif

<table class="meta">
    <thead>
        <tr>
            <th style="width: 22%;">{{ $t('quotation_number') }}</th>
            <th style="width: 30%;">{{ $t('representative') }}</th>
            <th style="width: 24%;">{{ $t('quotation_date') }}</th>
            <th style="width: 24%;">{{ $t('valid_until') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $numberLabel }}</td>
            <td>{{ $document['representative'] ?: '—' }}</td>
            <td>{{ $document['date'] }}</td>
            <td>{{ $document['valid_until'] ?: '—' }}</td>
        </tr>
    </tbody>
</table>

<div class="items-wrap">
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
        </tbody>
    </table>
</div>

@if ($document['notes'])
    <div class="notes"><strong>{{ $t('notes') }}</strong><br>{{ $document['notes'] }}</div>
@endif
@if ($document['terms'])
    <div class="notes"><strong>{{ $t('terms') }}</strong><br>{{ $document['terms'] }}</div>
@endif

<div class="closing">
    <table class="closing-inner">
        <tr>
            <td style="width: 55%;">&nbsp;</td>
            <td style="width: 45%;">
                <table class="totals">
                    @if ($has_discount)
                        <tr><td class="lbl">{{ $t('subtotal') }}</td><td class="val">{{ $totals['subtotal'] }} {{ $currency }}</td></tr>
                        <tr><td class="lbl">{{ $t('discount') }}</td><td class="val">- {{ $totals['discount'] }} {{ $currency }}</td></tr>
                        <tr class="strong"><td class="lbl">{{ $t('total_ht') }}</td><td class="val">{{ $totals['net'] }} {{ $currency }}</td></tr>
                    @else
                        <tr><td class="lbl">{{ $t('total_ht') }}</td><td class="val">{{ $totals['subtotal'] }} {{ $currency }}</td></tr>
                    @endif
                    @foreach ($tax_lines as $taxLine)
                        <tr><td class="lbl">{{ $taxLine['label'] }} ({{ $taxLine['rate'] }})</td><td class="val">{{ $taxLine['amount'] }} {{ $currency }}</td></tr>
                    @endforeach
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

    @if ($metadata['issued_at'])
        <p class="issued-meta">{{ $t('issued_by') }} {{ $metadata['issued_by'] ?: '—' }} · {{ $metadata['issued_at'] }}</p>
    @endif
</div>

@include('documents.partials.stamp', ['stamp' => $stamp ?? null])

</body>
</html>
