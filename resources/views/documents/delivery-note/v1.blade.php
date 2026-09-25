@php
    $locale = config('documents.locale');
    $t = fn (string $key) => __('documents.'.$key, locale: $locale);
    $accent = $seller['accent_color'] ?? \App\Services\DocumentSellerProfile::DEFAULT_ACCENT_COLOR;
    $ink = '#1f211d';
    $numberLabel = $document['number'] ?: ($watermark ?: '—');
    $recipientLabel = $buyer['company'] ?: ($buyer['name'] ?: '—');
    $displayWebsite = preg_replace('#^https?://#i', '', trim((string) ($seller['website'] ?? '')));
    $displayWebsite = rtrim($displayWebsite, '/');

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
        @page { margin: 20mm 13mm 22mm; }
        * { box-sizing: border-box; }
        body { color: {{ $ink }}; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.42; }

        .runfoot {
            position: fixed;
            bottom: -14mm; left: 0; right: 0;
            border-top: 1px solid #d7d7cf;
            padding-top: 3px;
            font-size: 10px;
            font-style: italic;
            color: #666;
            text-align: center;
            line-height: 1.30;
        }
        .watermark-text {
            position: fixed;
            top: 40%; left: 12%;
            font-size: 90px; font-weight: bold;
            color: rgba(176, 69, 59, .12);
            transform: rotate(-26deg);
        }

        table.masthead { width: 100%; border-collapse: collapse; }
        table.masthead td { vertical-align: top; }
        .logo { max-height: 100px; max-width: 300px; margin-bottom: 16px; min-width: 300px; }
        .seller { color: #444; font-size: 8.5px; }
        .dest { font-size: 8.5px; }
        .dest .lbl { font-weight: bold; letter-spacing: .08em; }
        .dest .name { font-weight: bold; color: {{ $accent }}; font-size: 10px; }
        .dest-details { margin-top: 6px; }

        h1.title {
            margin: 18px 0 12px;
            text-align: center;
            font-size: 24px; font-weight: bold; letter-spacing: .06em;
            color: {{ $ink }};
        }

        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.meta th {
            border: 1px solid #c9c9c2; padding: 5px 6px;
            font-size: 8px; font-weight: normal; text-align: center; color: #555;
        }
        table.meta td { border: 1px solid #c9c9c2; padding: 6px; text-align: center; }

        table.items { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; margin-top: 6px; }
        table.items thead { display: table-header-group; }
        table.items tr { page-break-inside: avoid; }
        table.items th {
            background: {{ $accent }}; color: #fff;
            font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em;
            padding: 6px 5px; text-align: left;
        }
        table.items td {
            border-bottom: 1px solid #e2e2da; padding: 5px 5px;
            vertical-align: top; word-wrap: break-word; overflow-wrap: break-word;
        }
        .num { text-align: right; white-space: nowrap; }
        .des-sub { color: #777; font-size: 8px; }

        .notes { margin-top: 14px; white-space: pre-line; page-break-inside: avoid; }

        table.signatures { width: 100%; border-collapse: collapse; margin-top: 18px; page-break-inside: avoid; }
        table.signatures td { width: 50%; padding: 0 10px; vertical-align: top; }
        .sig-box { border: 1px solid #c9c9c2; border-radius: 3px; padding: 8px; min-height: 40mm; }
        .sig-label { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; color: #555; }
        .sig-line { margin-top: 25mm; border-top: 1px solid #c9c9c2; padding-top: 3px; font-size: 7.5px; color: #888; }

        .issued-meta { margin-top: 14px; color: #888; font-size: 8px; }

        /* Keep fixed running elements inside the reserved @page margins.
           Negative offsets that reach beyond the page box can make Chromium
           create a footer-only second page in print preview. */
        .runhead, .runfoot { page-break-inside: avoid; }
        @media print {
            html, body { margin: 0; padding: 0; }
        }
        @include('documents.partials.commercial-style', ['accent' => $accent, 'ink' => $ink])
    </style>
</head>
<body>

@if ($watermark)
    <div class="watermark-text">{{ $watermark }}</div>
@elseif (($seller['show_invoice_watermark'] ?? false) && ($seller['logo'] ?? null))
    <div class="watermark-logo"><img src="{{ $seller['logo'] }}" alt=""></div>
@endif

<div class="runhead">
    <span class="r1">
        <span class="who">{{ $seller['legal_name'] }}</span>
        <span class="doc">{{ $title }} {{ $numberLabel }}</span>
    </span>
    <span class="r2">
        {{ $t('recipient') }} : {{ $recipientLabel }}
        <span class="sep">·</span>
        {{ $t('delivery_date') }} : {{ $document['date'] }}
    </span>
</div>

{{-- Legal footer (every page, sitting in the bottom margin) --}}
<div class="runfoot">
    @if ($footerLine1){{ $footerLine1 }}<br>@endif
    @if ($seller['email'] ?? null)Email : {{ $seller['email'] }}<br>@endif
    @if ($legalBits->isNotEmpty()){{ $legalBits->implode(' ; ') }}<br>@endif
    @if (data_get($seller, 'bank.rib'))RIB {{ data_get($seller, 'bank.name') ? data_get($seller, 'bank.name').' : ' : '' }}{{ data_get($seller, 'bank.rib') }}@endif
    @if ($seller['footer_text'] ?? null)<br>{{ $seller['footer_text'] }}@endif
</div>

<table class="masthead">
    <tr>
        <td style="width: 55%; padding-right: 28px;">
            @if ($seller['logo'] ?? null)
                <img class="logo" src="{{ $seller['logo'] }}" alt="">
            @else
                <div class="company-name">{{ ($seller['trade_name'] ?? null) ?: $seller['legal_name'] }}</div>
            @endif
            <div class="seller">
                @if (($seller['trade_name'] ?? null) && ($seller['legal_name'] ?? null))<div class="seller-row"><span class="info-value">{{ $seller['legal_name'] }}</span></div>@endif
                @if ($seller['address'] ?? null)<div class="seller-row"><span class="info-label">Adresse :</span> <span class="info-value">{{ $seller['address'] }}</span></div>@endif
                @if ($seller['tax_identifier'] ?? null)<div class="seller-row"><span class="info-label">ICE :</span> <span class="info-value">{{ $seller['tax_identifier'] }}</span></div>@endif
                @if ($seller['registration_number'] ?? null)<div class="seller-row"><span class="info-label">RC :</span> <span class="info-value">{{ $seller['registration_number'] }}</span></div>@endif
                @if ($seller['patente_number'] ?? null)<div class="seller-row"><span class="info-label">TP :</span> <span class="info-value">{{ $seller['patente_number'] }}</span></div>@endif
                @foreach (($seller['additional_identifiers'] ?? []) as $identifier)
                    <div class="seller-row"><span class="info-label">{{ $identifier['label'] }} :</span> <span class="info-value">{{ $identifier['value'] }}</span></div>
                @endforeach
                @if ($seller['phone'] ?? null)<div class="seller-row"><span class="info-label">Tél :</span> <span class="info-value">{{ $seller['phone'] }}</span></div>@endif
                @if ($seller['email'] ?? null)<div class="seller-row"><span class="info-label">Email :</span> <span class="info-value">{{ $seller['email'] }}</span></div>@endif
                @if ($displayWebsite !== '')<div class="seller-row"><span class="info-label">Web :</span> <span class="info-value">{{ $displayWebsite }}</span></div>@endif
            </div>
        </td>
        <td style="width: 45%; padding-left: 12px; padding-top: 68px;">
            <div class="dest">
                <div class="lbl">{{ $t('recipient') }}</div>
                <div class="name">{{ $recipientLabel }}</div>
                <div class="dest-details">
                @if ($buyer['company'] && $buyer['name'])<div class="dest-row"><span class="info-value">{{ $buyer['name'] }}</span></div>@endif
                @if ($buyer['address'])<div class="dest-row"><span class="info-label">Adresse :</span> <span class="info-value">{{ $buyer['address'] }}</span></div>@endif
                @if ($buyer['phone'])<div class="dest-row"><span class="info-label">Tél :</span> <span class="info-value">{{ $buyer['phone'] }}</span></div>@endif
                </div>
            </div>
        </td>
    </tr>
</table>

<h1 class="title">{{ $title }}</h1>

<table class="meta">
    <thead>
        <tr>
            <th style="width: 34%;">{{ $t('number') }}</th>
            <th style="width: 33%;">{{ $t('order') }}</th>
            <th style="width: 33%;">{{ $t('delivery_date') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $numberLabel }}</td>
            <td>{{ $document['order_number'] ?: '—' }}</td>
            <td>{{ $document['date'] }}</td>
        </tr>
    </tbody>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 44%;">{{ $t('designation') }}</th>
            <th style="width: 20%;">{{ $t('reference') }}</th>
            <th style="width: 18%;">{{ $t('default_unit') }}</th>
            <th style="width: 18%;" class="num">{{ $t('quantity') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>
                    {{ $line['description'] }}
                    @if ($line['variant'])<div class="des-sub">{{ $line['variant'] }}</div>@endif
                </td>
                <td>{{ $line['sku'] ?: $line['reference'] ?: '—' }}</td>
                <td>{{ $line['unit'] ?: '—' }}</td>
                <td class="num">{{ $line['quantity'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($document['notes'])
    <div class="notes"><strong>{{ $t('notes') }}</strong><br>{{ $document['notes'] }}</div>
@endif

<table class="signatures">
    <tr>
        <td>
            <div class="sig-box">
                <div class="sig-label">{{ $t('delivered_by') }}</div>
                <div class="sig-line">{{ $t('date') }} / {{ $t('signature') }}</div>
            </div>
        </td>
        <td>
            <div class="sig-box">
                <div class="sig-label">{{ $t('received_by') }}</div>
                <div class="sig-line">{{ $t('date') }} / {{ $t('signature') }}</div>
            </div>
        </td>
    </tr>
</table>

</body>
</html>
