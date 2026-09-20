<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Situation mensuelle</title>
<style>
    @page { margin: 18mm 14mm 22mm; }
    * { box-sizing: border-box; }
    body { color: #1f211d; font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; line-height: 1.45; }
    .section { page-break-before: always; }
    .section:first-child { page-break-before: avoid; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    h2 { font-size: 13px; margin: 0 0 10px; color: #4a5568; font-weight: normal; }
    .meta { color: #64748b; font-size: 9px; margin-bottom: 14px; }
    .cards { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .cards td { border: 1px solid #e2e8f0; padding: 8px 10px; width: 25%; vertical-align: top; }
    .cards .label { font-size: 8px; text-transform: uppercase; color: #64748b; letter-spacing: .03em; }
    .cards .value { font-size: 13px; font-weight: bold; margin-top: 3px; }
    table.recon { width: 60%; border-collapse: collapse; margin-bottom: 16px; }
    table.recon td { padding: 3px 8px; font-size: 9.5px; }
    table.recon td.amount { text-align: right; white-space: nowrap; }
    table.recon tr.total td { border-top: 1px solid #1f211d; font-weight: bold; }
    .variance { color: #92400e; font-size: 8.5px; margin-top: 4px; }
    table.lines { width: 100%; border-collapse: collapse; page-break-inside: auto; margin-top: 6px; }
    table.lines thead { display: table-header-group; }
    table.lines tr { page-break-inside: avoid; }
    table.lines th { background: #1f211d; color: #fff; font-size: 8px; text-transform: uppercase; padding: 5px 6px; text-align: left; }
    table.lines td { border-bottom: 1px solid #e5e9f0; padding: 5px 6px; font-size: 9px; }
    table.lines td.num { text-align: right; white-space: nowrap; }
    .empty { color: #94a3b8; font-style: italic; }
</style>
</head>
<body>
@foreach ($sections as $section)
    <div class="section">
        <h1>Situation mensuelle — {{ $section['label'] }}</h1>
        <h2>{{ $organizationName }}{{ $storeName ? ' · '.$storeName : ' · Toutes les boutiques' }}</h2>
        <p class="meta">Généré le {{ $generatedAt }}</p>

        <table class="cards">
            <tr>
                <td><div class="label">Ventes brutes</div><div class="value">{{ $section['ventes'] }}</div></td>
                <td><div class="label">Ventes nettes</div><div class="value">{{ $section['ventes_nettes'] }}</div></td>
                <td><div class="label">Facturation brute</div><div class="value">{{ $section['facturation_brute'] }}</div></td>
            </tr>
            <tr>
                <td><div class="label">Avoirs émis</div><div class="value">{{ $section['avoirs'] }}</div></td>
                <td><div class="label">Facturation nette</div><div class="value">{{ $section['facturation'] }}</div></td>
                <td><div class="label">Net encaissé</div><div class="value">{{ $section['net_encaisse'] }}</div></td>
                <td><div class="label">Remboursements</div><div class="value">{{ $section['remboursements'] }}</div></td>
            </tr>
        </table>

        <table class="recon">
            <tr><td>Position nette début (créances - à rembourser)</td><td class="amount">{{ $section['position_nette_debut'] }}</td></tr>
            <tr><td>+ Facturation nette</td><td class="amount">{{ $section['facturation'] }}</td></tr>
            <tr><td>- Encaissements bruts</td><td class="amount">{{ $section['encaissements'] }}</td></tr>
            <tr><td>+ Remboursements</td><td class="amount">{{ $section['remboursements'] }}</td></tr>
            <tr class="total"><td>= Position nette fin</td><td class="amount">{{ $section['position_nette_fin'] }}</td></tr>
            <tr><td>dont créances clients</td><td class="amount">{{ $section['creances_fin'] }}</td></tr>
            <tr><td>dont obligations de remboursement</td><td class="amount">{{ $section['obligations_remboursement'] }}</td></tr>
        </table>
        @if($section['has_variance'])
            <p class="variance">Écart de réconciliation de la position client : {{ $section['variance'] }}. Vérifier notamment les paiements de commandes non encore facturées.</p>
        @endif

        <table class="lines">
            <thead>
                <tr>
                    <th>N° facture</th><th>Date</th><th>Client</th><th class="num">Total TTC</th><th>Statut</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($section['invoices'] as $invoice)
                    <tr>
                        <td>{{ $invoice['invoice_number'] }}</td>
                        <td>{{ $invoice['invoice_date'] }}</td>
                        <td>{{ $invoice['customer'] }}</td>
                        <td class="num">{{ $invoice['total_incl_tax'] }}</td>
                        <td>{{ $invoice['status_label'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">Aucune facture émise sur cette période.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endforeach
</body>
</html>
