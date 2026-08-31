<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $document['number'] }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        * { box-sizing: border-box; }
        body { color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.45; }
        h1 { font-size: 24px; margin: 0; text-transform: uppercase; }
        h2 { color: #475569; font-size: 10px; letter-spacing: .08em; margin: 0 0 6px; text-transform: uppercase; }
        .header, .parties { display: table; table-layout: fixed; width: 100%; }
        .header > div, .parties > div { display: table-cell; vertical-align: top; width: 50%; }
        .right { text-align: right; }
        .box { border: 1px solid #d7dde7; padding: 10px; }
        .parties { border-spacing: 8px; margin: 20px -8px 18px; }
        table.lines { border-collapse: collapse; page-break-inside: auto; width: 100%; }
        table.lines thead { display: table-header-group; }
        table.lines tr { page-break-inside: avoid; }
        th { background: #172033; color: white; font-size: 8px; padding: 7px 5px; text-align: left; text-transform: uppercase; }
        td { border-bottom: 1px solid #e5e9f0; padding: 7px 5px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals { border-collapse: collapse; margin: 18px 0 0 auto; width: 45%; }
        .totals td { padding: 5px; }
        .totals .grand { border-top: 2px solid #172033; font-size: 13px; font-weight: bold; }
        .watermark { color: rgba(185, 28, 28, .18); font-size: 68px; font-weight: bold; left: 12%; position: fixed; top: 42%; transform: rotate(-28deg); z-index: -1; }
        .notes { margin-top: 22px; page-break-inside: avoid; white-space: pre-line; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
@if ($watermark)<div class="watermark">{{ $watermark }}</div>@endif
<div class="header">
    <div>
        <h1>{{ $title }}</h1>
        <div class="muted">{{ __('documents.number', locale: config('documents.locale')) }} : {{ $document['number'] ?: '—' }}</div>
    </div>
    <div class="right">
        <strong>{{ __('documents.date', locale: config('documents.locale')) }} :</strong> {{ $document['date'] }}<br>
        <strong>{{ __('documents.order', locale: config('documents.locale')) }} :</strong> {{ $document['order_number'] ?: '—' }}
    </div>
</div>
<div class="parties">
    <div class="box">
        <h2>{{ __('documents.seller', locale: config('documents.locale')) }}</h2>
        <strong>{{ $seller['legal_name'] }}</strong><br>
        @if ($seller['trade_name']){{ $seller['trade_name'] }}<br>@endif
        @if ($seller['address']){{ $seller['address'] }}<br>@endif
        @if ($seller['phone']){{ $seller['phone'] }}<br>@endif
        @if ($seller['email']){{ $seller['email'] }}<br>@endif
        @if ($seller['tax_identifier'])ID fiscal : {{ $seller['tax_identifier'] }}<br>@endif
        @if ($seller['registration_number'])RC : {{ $seller['registration_number'] }}<br>@endif
        @if ($seller['website']){{ $seller['website'] }}<br>@endif
        @foreach ($seller['additional_identifiers'] ?? [] as $identifier){{ $identifier['label'] }} : {{ $identifier['value'] }}<br>@endforeach
        @if (data_get($seller, 'store.name'))<span class="muted">{{ data_get($seller, 'store.name') }} ({{ data_get($seller, 'store.code') }})</span><br>@endif
        @if (data_get($seller, 'store.address'))<span class="muted">{{ data_get($seller, 'store.address') }}</span><br>@endif
        @if (data_get($seller, 'store.phone'))<span class="muted">{{ data_get($seller, 'store.phone') }}</span><br>@endif
        @if (data_get($seller, 'store.email'))<span class="muted">{{ data_get($seller, 'store.email') }}</span>@endif
    </div>
    <div class="box">
        <h2>{{ __('documents.customer', locale: config('documents.locale')) }}</h2>
        <strong>{{ $buyer['company'] ?: $buyer['name'] }}</strong><br>
        @if ($buyer['company'] && $buyer['name']){{ $buyer['name'] }}<br>@endif
        @if ($buyer['address']){{ $buyer['address'] }}<br>@endif
        @if ($buyer['phone']){{ $buyer['phone'] }}<br>@endif
        @if ($buyer['email']){{ $buyer['email'] }}<br>@endif
        @if ($buyer['tax_identifier'])ID fiscal : {{ $buyer['tax_identifier'] }}@endif
    </div>
</div>
<table class="lines">
    <thead><tr><th>{{ __('documents.description', locale: config('documents.locale')) }}</th><th>{{ __('documents.reference', locale: config('documents.locale')) }}</th><th class="num">{{ __('documents.quantity', locale: config('documents.locale')) }}</th><th class="num">{{ __('documents.unit_price', locale: config('documents.locale')) }}</th><th class="num">{{ __('documents.line_discount', locale: config('documents.locale')) }}</th><th class="num">{{ __('documents.tax', locale: config('documents.locale')) }}</th><th class="num">{{ __('documents.total', locale: config('documents.locale')) }}</th></tr></thead>
    <tbody>@foreach ($lines as $line)<tr><td>{{ $line['description'] }}@if ($line['variant'])<br><span class="muted">{{ $line['variant'] }}</span>@endif</td><td>{{ $line['sku'] ?: $line['reference'] }}</td><td class="num">{{ $line['quantity'] }} {{ $line['unit'] }}</td><td class="num">{{ $line['unit_price'] }}</td><td class="num">{{ $line['discount'] }}</td><td class="num">{{ $line['tax_name'] }} {{ $line['tax_rate'] }}<br>{{ $line['tax_amount'] }}</td><td class="num">{{ $line['total'] }}</td></tr>@endforeach</tbody>
</table>
<table class="totals">
    <tr><td>{{ __('documents.subtotal', locale: config('documents.locale')) }}</td><td class="num">{{ $totals['subtotal'] }} {{ $document['currency'] }}</td></tr>
    <tr><td>{{ __('documents.discount', locale: config('documents.locale')) }}</td><td class="num">{{ $totals['discount'] }} {{ $document['currency'] }}</td></tr>
    <tr><td>{{ __('documents.tax_total', locale: config('documents.locale')) }}</td><td class="num">{{ $totals['tax'] }} {{ $document['currency'] }}</td></tr>
    <tr class="grand"><td>{{ __('documents.total', locale: config('documents.locale')) }}</td><td class="num">{{ $totals['total'] }} {{ $document['currency'] }}</td></tr>
</table>
@if ($document['notes'])<div class="notes"><h2>{{ __('documents.notes', locale: config('documents.locale')) }}</h2>{{ $document['notes'] }}</div>@endif
@if ($metadata['issued_at'])<p class="muted">{{ __('documents.issued_by', locale: config('documents.locale')) }} {{ $metadata['issued_by'] ?: '—' }} · {{ $metadata['issued_at'] }}</p>@endif
</body>
</html>
