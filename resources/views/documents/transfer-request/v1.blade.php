@php
    $twoOnOne = $two_on_one_page;
    $copies = [
        'EXEMPLAIRE 1 — DÉPÔT / ARCHIVE',
        'EXEMPLAIRE 2 — TRANSPORT / DESTINATION',
    ];
    $accent = $seller['accent_color'] ?? '#2b3a30';
    $bodySize = $twoOnOne ? '8px' : '10px';
    $logoMax = $twoOnOne ? '34px' : '52px';
    $h1Size = $twoOnOne ? '15px' : '22px';
    $cutMargin = $twoOnOne ? '7mm 0' : '0';

    $sourceLabel = ($source['name'] ?? '—');
    if (! empty($source['code'])) {
        $sourceLabel .= ' ('.$source['code'].')';
    }
    $destinationLabel = ($destination['name'] ?? '—');
    if (! empty($destination['code'])) {
        $destinationLabel .= ' ('.$destination['code'].')';
    }

    $driverLine = $driver['name'] ?: '—';
    if (! empty($driver['phone'])) {
        $driverLine .= ' · '.$driver['phone'];
    }

    $vehicleLine = trim((string) ($driver['vehicle'] ?? ''));
    if (! empty($driver['registration'])) {
        $vehicleLine = trim($vehicleLine.' ('.$driver['registration'].')');
    }
    if ($vehicleLine === '') {
        $vehicleLine = '—';
    }

    $showVehicleRow = ! empty($driver['vehicle']) || ! empty($driver['registration']) || ! empty($note);
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $document['number'] }}</title>
<style>
    @page { margin: 11mm 12mm; }
    * { box-sizing: border-box; }
    body { color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: {{ $bodySize }}; line-height: 1.4; }
    .copy.full-page { page-break-before: always; }
    .copy.full-page.first { page-break-before: avoid; }
    .cut { border-top: 1px dashed #94a3b8; color: #94a3b8; font-size: 8px; letter-spacing: .3em; margin: {{ $cutMargin }}; padding-top: 2px; text-align: center; }
    .copy-label { background: {{ $accent }}; color: #fff; font-size: 8px; font-weight: bold; letter-spacing: .12em; margin-bottom: 6px; padding: 4px 8px; }
    .head { display: table; width: 100%; }
    .head > div { display: table-cell; vertical-align: top; }
    .logo { max-height: {{ $logoMax }}; max-width: 170px; }
    h1 { color: {{ $accent }}; font-size: {{ $h1Size }}; margin: 0; text-transform: uppercase; }
    .muted { color: #64748b; }
    .meta { border: 1px solid #d7dde7; border-collapse: collapse; margin: 8px 0; width: 100%; }
    .meta td { border: 1px solid #d7dde7; padding: 4px 6px; }
    .meta .k { background: #f1f5f9; font-size: 8px; text-transform: uppercase; white-space: nowrap; width: 26%; }
    table.lines { border-collapse: collapse; width: 100%; }
    table.lines th { background: {{ $accent }}; color: #fff; font-size: 8px; padding: 5px; text-align: left; text-transform: uppercase; }
    table.lines td { border-bottom: 1px solid #e5e9f0; padding: 5px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .sign { display: table; margin-top: 12px; width: 100%; }
    .sign > div { border: 1px solid #cbd5e1; display: table-cell; padding: 6px 8px; vertical-align: top; width: 33.33%; }
    .sign h3 { font-size: 8px; letter-spacing: .06em; margin: 0 0 4px; text-transform: uppercase; }
    .sign .line { border-bottom: 1px solid #94a3b8; margin-top: 14px; }
    .sign .lbl { color: #64748b; font-size: 7.5px; margin-top: 2px; }
    .reason-tag { color: #64748b; font-size: 7.5px; }
    .disclaimer { color: #64748b; font-size: 7.5px; margin-top: 8px; }
</style>
</head>
<body>
@foreach ($copies as $index => $label)
    @if ($twoOnOne && $index === 1)
        <div class="cut">✂ &nbsp; COUPER ICI &nbsp; — — — — — — — — — — — — — — — — — — — — — — — — — — — — — — — —</div>
    @endif
    <div class="copy {{ $twoOnOne ? 'half' : 'full-page' }} {{ $index === 0 ? 'first' : '' }}">
        <div class="copy-label">{{ $label }}</div>

        <div class="head">
            <div>
                @if (! empty($seller['logo']))
                    <img class="logo" src="{{ $seller['logo'] }}" alt="">
                @else
                    <strong>{{ $seller['legal_name'] }}</strong>
                @endif
                <div class="muted">
                    {{ $seller['legal_name'] }}
                    @if (! empty($seller['address'])) · {{ $seller['address'] }} @endif
                    <br>
                    @if (! empty($seller['phone'])) {{ $seller['phone'] }} @endif
                    @if (! empty($seller['email'])) · {{ $seller['email'] }} @endif
                    @if (! empty($seller['tax_identifier'])) <br>ID fiscal : {{ $seller['tax_identifier'] }} @endif
                    @if (! empty($seller['registration_number'])) · RC : {{ $seller['registration_number'] }} @endif
                </div>
            </div>
            <div style="text-align: right;">
                <h1>{{ $title }}</h1>
                <div><strong>{{ $title }} — {{ $document['number'] }}</strong></div>
                <div class="muted">Demande de transfert : {{ $document['number'] }}</div>
                <div class="muted">Date : {{ $document['date'] }} · {{ $document['status_label'] }}</div>
            </div>
        </div>

        <table class="meta">
            <tr>
                <td class="k">Entrepôt d'origine</td>
                <td>{{ $sourceLabel }}</td>
                <td class="k">Entrepôt destination</td>
                <td>{{ $destinationLabel }}</td>
            </tr>
            <tr>
                <td class="k">Commande liée</td>
                <td>{{ $order_number ?: '—' }}</td>
                <td class="k">Chauffeur / Livreur</td>
                <td>{{ $driverLine }}</td>
            </tr>
            @if ($showVehicleRow)
                <tr>
                    <td class="k">Véhicule / Immatriculation</td>
                    <td>{{ $vehicleLine }}</td>
                    <td class="k">Note</td>
                    <td>{{ $note ?: '—' }}</td>
                </tr>
            @endif
        </table>

        <table class="lines">
            <thead>
                <tr>
                    <th style="width: 20%;">Référence</th>
                    <th>Désignation</th>
                    <th style="width: 14%;" class="num">Quantité</th>
                    <th style="width: 12%;">Unité</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line['reference'] }}</td>
                        <td>
                            {{ $line['description'] ?: '—' }}
                            <span class="reason-tag"> · {{ $line['reason'] }}</span>
                        </td>
                        <td class="num">{{ $line['quantity'] }}</td>
                        <td>{{ $line['unit'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="sign">
            <div>
                <h3>Responsable dépôt / Sortie</h3>
                <div class="line"></div>
                <div class="lbl">Nom</div>
                <div class="line"></div>
                <div class="lbl">Signature</div>
            </div>
            <div>
                <h3>Chauffeur / Livreur</h3>
                <div class="line">{{ $driver['name'] ?: '' }}</div>
                <div class="lbl">Nom</div>
                <div class="line"></div>
                <div class="lbl">Signature</div>
            </div>
            <div>
                <h3>Réception / Destination</h3>
                <div class="line"></div>
                <div class="lbl">Nom</div>
                <div class="line"></div>
                <div class="lbl">Date / Heure</div>
                <div class="line"></div>
                <div class="lbl">Signature / Cachet</div>
            </div>
        </div>

        <p class="disclaimer">
            Document logistique interne — aucune valeur commerciale ni comptable. La sortie de stock est enregistrée dans l'ERP à la réception numérique.
        </p>
    </div>
@endforeach
</body>
</html>
