@php
    $accent = $seller['accent_color'] ?? \App\Services\DocumentSellerProfile::DEFAULT_ACCENT_COLOR;
    $ink = '#1f211d';
    $storeLabel = $storeName ?? 'Toutes les boutiques';

    $sellerContact = collect([
        ($seller['phone'] ?? null) ? 'Tél : '.$seller['phone'] : null,
        ($seller['email'] ?? null) ? $seller['email'] : null,
    ])->filter()->implode('   ');

    $sellerLegal = collect([
        ($seller['tax_identifier'] ?? null) ? 'ICE : '.$seller['tax_identifier'] : null,
        ($seller['registration_number'] ?? null) ? 'RC : '.$seller['registration_number'] : null,
        ($seller['patente_number'] ?? null) ? 'TP : '.$seller['patente_number'] : null,
    ])->filter()->implode('   ');
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>CA encaissé</title>
<style>
    @page { margin: 14mm 12mm 18mm; }
    * { box-sizing: border-box; }
    body { color: {{ $ink }}; font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; line-height: 1.4; }
    .section { page-break-before: always; }
    .section:first-child { page-break-before: avoid; }

    /* --- Branded masthead, once per month section --- */
    table.masthead { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.masthead td { vertical-align: top; }
    .logo { max-height: 46px; max-width: 170px; margin-bottom: 4px; }
    .company-name { font-size: 13px; font-weight: bold; text-transform: uppercase; color: {{ $ink }}; margin-bottom: 3px; }
    .seller-meta { color: #555; font-size: 7.5px; line-height: 1.55; }
    .masthead-right { text-align: right; }
    .report-title { font-size: 20px; font-weight: bold; letter-spacing: .04em; color: {{ $accent }}; }
    .report-period { font-size: 12px; font-weight: bold; color: {{ $ink }}; margin-top: 1px; }
    .report-store { font-size: 8.5px; color: #666; margin-top: 2px; }

    .meta { color: #64748b; font-size: 7.5px; margin: 0 0 10px; padding-top: 6px; border-top: 1px solid #d7d7cf; }

    /* --- Data table: fixed layout so column widths are exact and stable
           across both orientations; nowrap on every short/compact column so
           a date like 12/09/2026 never breaks onto two lines. --- */
    table.lines { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
    table.lines thead { display: table-header-group; }
    table.lines tr { page-break-inside: avoid; }
    table.lines th {
        background: {{ $accent }}; color: #fff;
        font-size: 7px; font-weight: bold; text-transform: uppercase; letter-spacing: .02em;
        padding: 5px 4px; text-align: left; white-space: nowrap;
    }
    table.lines th.num { text-align: right; }
    table.lines td {
        border-bottom: 1px solid #e5e9f0; padding: 4px 4px;
        vertical-align: top; font-size: 8px;
    }
    table.lines td.nowrap { white-space: nowrap; }
    table.lines td.num { text-align: right; white-space: nowrap; }
    table.lines td.wrap { word-wrap: break-word; overflow-wrap: break-word; }

    tfoot.total-row { page-break-inside: avoid; }
    tfoot.total-row td {
        border-top: 2px solid {{ $ink }}; border-bottom: none;
        padding: 7px 4px; font-size: 10px; font-weight: bold; color: {{ $ink }};
    }
    tfoot.total-row td.num { text-align: right; white-space: nowrap; }

    .empty { color: #94a3b8; font-style: italic; }
</style>
</head>
<body>
@foreach ($sections as $section)
    <div class="section">
        <table class="masthead">
            <tr>
                <td style="width: 58%;">
                    @if ($seller['logo'] ?? null)
                        <img class="logo" src="{{ $seller['logo'] }}" alt="">
                    @else
                        <div class="company-name">{{ $seller['legal_name'] }}</div>
                    @endif
                    <div class="seller-meta">
                        @if ($seller['address'] ?? null){{ $seller['address'] }}<br>@endif
                        @if ($sellerContact !== ''){{ $sellerContact }}<br>@endif
                        @if ($sellerLegal !== ''){{ $sellerLegal }}@endif
                    </div>
                </td>
                <td class="masthead-right" style="width: 42%;">
                    <div class="report-title">CA encaissé</div>
                    <div class="report-period">{{ $section['label'] }}</div>
                    <div class="report-store">{{ $storeLabel }}</div>
                </td>
            </tr>
        </table>
        <p class="meta">Encaissements réellement reçus pendant la période · Généré le {{ $generatedAt }}</p>

        <table class="lines">
            <thead>
                <tr>
                    <th style="width: 7%;">Numéro</th>
                    <th style="width: 6%;">Date de vente</th>
                    <th style="width: 6%;">Date de paiement</th>
                    <th style="width: 8%;">N° facture / commande</th>
                    <th style="width: 4%;" class="num">Qté</th>
                    <th style="width: 8%;">Référence</th>
                    <th style="width: 21%;">Désignation</th>
                    <th style="width: 12%;">Client</th>
                    <th style="width: 9%;">Mode de paiement</th>
                    <th style="width: 9%;" class="num">Montant encaissé</th>
                    <th style="width: 10%;">Statut</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($section['rows'] as $row)
                    {{-- One PDF row per SOLD LINE, never a truncated "+N autres"
                         summary. The identifying/payment columns are repeated
                         on every line row of the group (never merged — Dompdf
                         table rowspan across a page break is unreliable) EXCEPT
                         "Montant encaissé", left blank after the first line so
                         the figure only ever appears once per payment. --}}
                    @foreach ($row['lines'] as $lineIndex => $line)
                        <tr>
                            <td class="nowrap">{{ $row['payment_number'] }}</td>
                            <td class="nowrap">{{ $row['sale_date'] }}</td>
                            <td class="nowrap">{{ $row['payment_date'] }}</td>
                            <td class="nowrap">{{ $row['reference'] }}</td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td class="nowrap">{{ $line['reference'] }}</td>
                            <td class="wrap">{{ $line['designation'] }}</td>
                            <td class="wrap">{{ $row['customer'] }}</td>
                            <td class="nowrap">{{ $row['method_label'] }}</td>
                            <td class="num">{{ $lineIndex === 0 ? $row['amount'].' DH' : '' }}</td>
                            <td class="wrap">{{ $row['status_label'] }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="11" class="empty">Aucun encaissement sur cette période.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="total-row">
                <tr>
                    <td colspan="9">CA encaissé du mois</td>
                    <td class="num">{{ $section['total'] }} DH</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endforeach
</body>
</html>
