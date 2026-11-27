<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Relevé de compte client</title>
<style>
    @page { margin: 18mm 14mm 22mm; }
    * { box-sizing: border-box; }
    body { color: #1f211d; font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; line-height: 1.45; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    h2 { font-size: 12px; margin: 0 0 10px; color: #4a5568; font-weight: normal; }
    .meta { color: #64748b; font-size: 9px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { background: #1f211d; color: #fff; font-size: 8px; text-transform: uppercase; padding: 5px 6px; text-align: left; }
    td { border-bottom: 1px solid #e5e9f0; padding: 5px 6px; font-size: 9px; }
    .num { text-align: right; white-space: nowrap; }
    tr.total td { border-top: 1px solid #1f211d; font-weight: bold; }
    .note { color: #92400e; font-size: 8.5px; margin-top: 8px; }
</style>
</head>
<body>
@php($fmt = fn ($v) => number_format((float) $v, 2, ',', ' '))
<h1>Relevé de compte client</h1>
<h2>{{ $customer->display_name }}@if($customer->company_name && $customer->company_name !== $customer->display_name) — {{ $customer->company_name }}@endif</h2>
<div class="meta">{{ $organizationName }} · Période du {{ $statement['from'] }} au {{ $statement['to'] }} · Devise : {{ $statement['currency_code'] ?? '—' }} · Édité le {{ $generatedAt }}</div>
<table>
    <thead>
        <tr><th>Date</th><th>Type</th><th>Référence</th><th>Description</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde</th></tr>
    </thead>
    <tbody>
        <tr class="total"><td colspan="6">Solde d’ouverture</td><td class="num">{{ $fmt($statement['opening']) }}</td></tr>
        @foreach ($statement['rows'] as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['type'] }}</td>
                <td>{{ $row['reference'] }}</td>
                <td>{{ $row['description'] }}</td>
                <td class="num">{{ bccomp($row['debit'], '0', 4) === 0 ? '' : $fmt($row['debit']) }}</td>
                <td class="num">{{ bccomp($row['credit'], '0', 4) === 0 ? '' : $fmt($row['credit']) }}</td>
                <td class="num">{{ $fmt($row['balance']) }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="4">Total de la période / solde de clôture</td><td class="num">{{ $fmt($statement['total_debit']) }}</td><td class="num">{{ $fmt($statement['total_credit']) }}</td><td class="num">{{ $fmt($statement['closing']) }}</td></tr>
    </tbody>
</table>
@if ($statement['truncated'])
    <p class="note">Relevé tronqué : réduisez la période.</p>
@endif
<p class="note">Débit = ce que le client doit en plus (commandes confirmées, remboursements) ; crédit = ce qui réduit sa dette (règlements affectés, retours, avances non affectées). Un solde d’ouverture n’est ni une vente, ni une facture, ni un encaissement.</p>
</body>
</html>
