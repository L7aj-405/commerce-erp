<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Rapport des commissions</title>
<style>
    @page { margin: 16mm 12mm 20mm; }
    * { box-sizing: border-box; }
    body { color: #1f211d; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.45; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    h2 { font-size: 12px; margin: 0 0 8px; color: #4a5568; font-weight: normal; }
    h3 { font-size: 11px; margin: 14px 0 6px; }
    .meta { color: #64748b; font-size: 8.5px; margin-bottom: 12px; }
    .cards { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .cards td { border: 1px solid #e2e8f0; padding: 7px 9px; vertical-align: top; }
    .cards .label { font-size: 7.5px; text-transform: uppercase; color: #64748b; letter-spacing: .03em; }
    .cards .value { font-size: 12px; font-weight: bold; margin-top: 3px; }
    .negative { color: #b91c1c; }
    table.lines { width: 100%; border-collapse: collapse; page-break-inside: auto; }
    table.lines thead { display: table-header-group; }
    table.lines tr { page-break-inside: avoid; }
    table.lines th { background: #1f211d; color: #fff; font-size: 7.5px; text-transform: uppercase; padding: 5px 5px; text-align: left; }
    table.lines td { border-bottom: 1px solid #e5e9f0; padding: 4px 5px; font-size: 8.5px; }
    table.lines .num { text-align: right; white-space: nowrap; }
    .muted { color: #94a3b8; font-style: italic; }
    .note { color: #64748b; font-size: 8px; margin-top: 10px; }
</style>
</head>
<body>
    <h1>Rapport des commissions</h1>
    <h2>{{ $organizationName }} · du {{ $from }} au {{ $to }}</h2>
    <p class="meta">Filtres : {{ $filterLabel }} · Généré le {{ $generatedAt }}</p>

    <table class="cards">
        <tr>
            <td><div class="label">Commission brute</div><div class="value">{{ $kpis['gross'] }}</div></td>
            <td><div class="label">Retours / ajustements</div><div class="value negative">{{ $kpis['reversals'] }}</div></td>
            <td><div class="label">Commission nette</div><div class="value">{{ $kpis['net'] }}</div></td>
            <td><div class="label">En attente</div><div class="value">{{ $kpis['pending'] }}</div></td>
            <td><div class="label">Approuvée</div><div class="value">{{ $kpis['approved'] }}</div></td>
            <td><div class="label">Payée</div><div class="value">{{ $kpis['paid'] }}</div></td>
        </tr>
        <tr>
            <td><div class="label">Commandes</div><div class="value">{{ $kpis['order_count'] }}</div></td>
            <td><div class="label">Écritures</div><div class="value">{{ $kpis['entry_count'] }}</div></td>
            <td><div class="label">CA HT couvert</div><div class="value">{{ $kpis['revenue'] }}</div></td>
            @if($withCosts)
                <td><div class="label">Marge brute</div><div class="value">{{ $kpis['margin'] }}</div></td>
                <td><div class="label">Marge %</div><div class="value">{{ $kpis['margin_rate'] }}</div></td>
                <td><div class="label">Commission / marge</div><div class="value">{{ $kpis['ratio'] }}</div></td>
            @else
                <td colspan="3"><div class="label">Marge</div><div class="value muted">Non affichée (droit prix d'achat requis)</div></td>
            @endif
        </tr>
    </table>

    <h3>Synthèse par commercial</h3>
    <table class="lines">
        <thead>
            <tr>
                <th>Commercial</th><th class="num">Cmd.</th><th class="num">CA HT</th>
                @if($withCosts)<th class="num">Marge</th>@endif
                <th class="num">Brute</th><th class="num">Retours / ajust.</th><th class="num">Nette</th>
                <th class="num">En attente</th><th class="num">Approuvée</th><th class="num">Payée</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}@if($row['unattributed']) <span class="muted">(non payable)</span>@endif</td>
                    <td class="num">{{ $row['orders'] }}</td>
                    <td class="num">{{ $row['revenue'] }}</td>
                    @if($withCosts)<td class="num">{{ $row['margin'] }}</td>@endif
                    <td class="num">{{ $row['gross'] }}</td>
                    <td class="num negative">{{ $row['reversals'] }}</td>
                    <td class="num"><strong>{{ $row['net'] }}</strong></td>
                    <td class="num">{{ $row['pending'] }}</td>
                    <td class="num">{{ $row['approved'] }}</td>
                    <td class="num">{{ $row['paid'] }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="muted">Aucune écriture de commission sur cette période.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="note">Les montants proviennent exclusivement du registre des commissions (écritures immuables). Les retours, annulations et corrections sont comptés à leur date de survenance. Le détail ligne à ligne est disponible dans l’export Excel.</p>
</body>
</html>
