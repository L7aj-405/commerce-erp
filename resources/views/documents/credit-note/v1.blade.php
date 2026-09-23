@php
    $accent = $seller['accent_color'] ?? \App\Services\DocumentSellerProfile::DEFAULT_ACCENT_COLOR;
    $ink = '#1f211d';
    $title = 'Avoir';
    $numberLabel = $document['number'] ?? 'BROUILLON';
    $customerLabel = ($customer['company'] ?? null) ?: ($customer['name'] ?? '');
    $legalBits = collect([
        ($seller['registration_number'] ?? null) ? 'RC N° : '.$seller['registration_number'] : null,
        ($seller['patente_number'] ?? null) ? 'TP : '.$seller['patente_number'] : null,
        ($seller['tax_identifier'] ?? null) ? 'ICE : '.$seller['tax_identifier'] : null,
    ])->filter();
    foreach (($seller['additional_identifiers'] ?? []) as $identifier) {
        $legalBits->push($identifier['label'].' : '.$identifier['value']);
    }
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
@page{margin:18mm 14mm 28mm}*{box-sizing:border-box}body{font-family:DejaVu Sans,sans-serif;color:#17211b;font-size:9px;line-height:1.4}h1{font-size:24px;margin:0;color:{{ $accent }} }.top{display:table;width:100%;margin-bottom:16px}.cell{display:table-cell;width:50%;vertical-align:top}.right{text-align:right}.logo{max-height:58px;max-width:210px;margin-bottom:7px}.box{border:1px solid #d9dfdb;padding:9px;margin:10px 0}.muted{color:#66736b}.meta{margin:8px 0 14px}table{width:100%;border-collapse:collapse}th{background:{{ $accent }};color:white;padding:6px 4px;text-align:left;font-size:7.5px;text-transform:uppercase}td{padding:6px 4px;border-bottom:1px solid #e2e6e3;vertical-align:top}.num{text-align:right;white-space:nowrap}.small{font-size:7.5px;color:#66736b}.summary{display:table;width:100%;margin-top:14px}.taxes,.totals{display:table-cell;vertical-align:top}.taxes{width:50%;padding-right:18px}.totals{width:50%}.totals td{padding:4px 6px}.total{font-size:13px;font-weight:bold;border-top:2px solid {{ $accent }}}.footer{position:fixed;bottom:-20mm;left:0;right:0;border-top:1px solid #d9dfdb;padding-top:4px;color:#777;font-size:7px;text-align:center;line-height:1.45}
.footer{font-size:7.8px;color:#4f524a}.credit-title{margin:18px 0 12px;text-align:center;font-size:26px;font-weight:bold;letter-spacing:.06em;color:{{ $ink }}}.origin-meta{margin:8px 0 14px;color:#343730;font-size:9.4px}.box{border:1px solid #c9c9c2;padding:9px;margin:10px 0;background:#fff}.summary table th{text-align:left}
@include('documents.partials.commercial-style', ['accent' => $accent, 'ink' => $ink])
</style>
</head>
<body>
@if (($seller['show_invoice_watermark'] ?? false) && ($seller['logo'] ?? null))
    <div class="watermark-logo"><img src="{{ $seller['logo'] }}" alt=""></div>
@endif

<div class="runhead">
    <span class="r1">
        <span class="who">{{ $seller['legal_name'] ?? '' }}</span>
        <span class="doc">{{ $title }} {{ $numberLabel }}</span>
    </span>
    <span class="r2">
        Client : {{ $customerLabel ?: '—' }}
        <span class="sep">·</span>
        Date : {{ $document['date'] }}
    </span>
</div>

<div class="top">
    <div class="cell">
        @if($seller['logo'] ?? null)
            <img class="logo" src="{{ $seller['logo'] }}" alt=""><br>
        @else
            <div class="company-name">{{ ($seller['trade_name'] ?? null) ?: ($seller['legal_name'] ?? '') }}</div>
        @endif
        <div class="seller">
            @if(($seller['trade_name'] ?? null) && ($seller['legal_name'] ?? null))<div class="seller-row"><span class="info-value">{{ $seller['legal_name'] }}</span></div>@endif
            @if($seller['address'] ?? null)<div class="seller-row"><span class="info-label">Adresse :</span> <span class="info-value">{{ $seller['address'] }}</span></div>@endif
            @if($seller['tax_identifier'] ?? null)<div class="seller-row"><span class="info-label">ICE :</span> <span class="info-value">{{ $seller['tax_identifier'] }}</span></div>@endif
            @if($seller['registration_number'] ?? null)<div class="seller-row"><span class="info-label">RC :</span> <span class="info-value">{{ $seller['registration_number'] }}</span></div>@endif
            @if($seller['patente_number'] ?? null)<div class="seller-row"><span class="info-label">TP :</span> <span class="info-value">{{ $seller['patente_number'] }}</span></div>@endif
            @foreach (($seller['additional_identifiers'] ?? []) as $identifier)
                <div class="seller-row"><span class="info-label">{{ $identifier['label'] }} :</span> <span class="info-value">{{ $identifier['value'] }}</span></div>
            @endforeach
            @if($seller['phone'] ?? null)<div class="seller-row"><span class="info-label">Tél :</span> <span class="info-value">{{ $seller['phone'] }}</span></div>@endif
            @if($seller['email'] ?? null)<div class="seller-row"><span class="info-label">Email :</span> <span class="info-value">{{ $seller['email'] }}</span></div>@endif
        </div>
    </div>
    <div class="cell right">
        <h1 class="credit-title">AVOIR</h1>
        <div class="info-value">{{ $numberLabel }}</div>
        <div><span class="info-label">Date d’émission :</span> <span class="info-value">{{ $document['date'] }}</span></div>
    </div>
</div>

<div class="box dest">
    <div class="lbl">Client</div>
    <div class="name">{{ $customerLabel }}</div>
    @if(!empty($customer['billing_address']))<div class="dest-row"><span class="info-label">Adresse :</span> <span class="info-value">{{ $customer['billing_address'] }}</span></div>@endif
    @if(!empty($customer['tax_identifier']))<div class="dest-row"><span class="info-label">Identifiant fiscal :</span> <span class="info-value">{{ $customer['tax_identifier'] }}</span></div>@endif
</div>

<p class="origin-meta">
    <span class="info-label">Facture d’origine :</span> <span class="info-value">{{ $document['invoice_number'] }}@if(($document['invoice_version'] ?? 1) > 1) — version {{ $document['invoice_version'] }}@endif</span>
    · <span class="info-label">Commande :</span> <span class="info-value">{{ $document['order_number'] }}</span>
</p>

<table class="items">
    <thead><tr><th>Réf. / SKU</th><th>Désignation</th><th class="num">Qté</th><th class="num">PU HT</th><th class="num">HT</th><th class="num">Remise</th><th class="num">TVA</th><th class="num">TTC</th></tr></thead>
    <tbody>
    @foreach($lines as $line)
        <tr>
            <td>{{ $line['reference'] ?: '—' }}@if($line['sku'] && $line['sku'] !== $line['reference'])<br><span class="small">SKU {{ $line['sku'] }}</span>@endif</td>
            <td>{{ $line['description'] }}@if($line['variant'])<br><span class="small">{{ $line['variant'] }}</span>@endif @if($line['presentation'])<br><span class="small">{{ $line['presentation'] }}</span>@endif</td>
            <td class="num">{{ $line['quantity'] }}</td>
            <td class="num">{{ $line['unit_price_ht'] }}</td>
            <td class="num">{{ $line['net_ht'] }}</td>
            <td class="num">{{ $line['has_discount'] ? $line['discount'] : '—' }}</td>
            <td class="num">{{ $line['tax_rate'] }}<br><span class="small">{{ $line['tax_amount'] }}</span></td>
            <td class="num">-{{ $line['total_ttc'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="summary">
    <div class="taxes">
        <table>
            <thead><tr><th>Taux</th><th class="num">Base HT</th><th class="num">TVA</th></tr></thead>
            <tbody>@foreach($tax_lines as $tax)<tr><td>{{ $tax['label'] }} {{ $tax['rate'] }}</td><td class="num">{{ $tax['base'] }}</td><td class="num">{{ $tax['amount'] }}</td></tr>@endforeach</tbody>
        </table>
    </div>
    <div class="totals">
        <table>
            <tr><td>Sous-total HT</td><td class="num">-{{ $totals['subtotal'] }}</td></tr>
            @if($has_discount)<tr><td>Remises</td><td class="num">{{ $totals['discount'] }}</td></tr>@endif
            <tr><td>Total HT net</td><td class="num">-{{ $totals['net'] }}</td></tr>
            <tr><td>TVA</td><td class="num">-{{ $totals['tax'] }}</td></tr>
            <tr class="total"><td>Total TTC</td><td class="num">-{{ $totals['total'] }} {{ $document['currency'] }}</td></tr>
        </table>
    </div>
</div>

<p class="muted"><strong>Motif :</strong> {{ $document['reason'] }}</p>
<div class="footer">
    @if($legalBits->isNotEmpty()){{ $legalBits->implode(' · ') }}<br>@endif
    @if($seller['footer_text'] ?? null){{ $seller['footer_text'] }}<br>@endif
    Document historique immuable — cet avoir ne constitue pas une preuve de remboursement.
</div>
</body>
</html>
