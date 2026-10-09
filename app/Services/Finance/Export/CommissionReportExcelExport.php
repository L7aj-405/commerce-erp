<?php

namespace App\Services\Finance\Export;

use App\Models\Organization;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionReportFilters;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Commission report workbook: Résumé / Commerciaux / Écritures.
 *
 * Ledger rows are streamed from a keyset-chunked LazyCollection straight into
 * an OpenSpout temp file, so memory stays constant regardless of ledger size;
 * the controller streams the file and deletes it after sending. Cost and
 * margin columns are only written when the exporter may view purchase costs.
 */
class CommissionReportExcelExport
{
    public function __construct(private readonly CommissionDashboardService $dashboard) {}

    /** @return array{path:string,filename:string,rows:int} */
    public function build(Organization $organization, CommissionReportFilters $filters, bool $withCosts, string $filterLabel): array
    {
        $path = tempnam(sys_get_temp_dir(), 'commission_report_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        $this->writeSummary($writer, $organization, $filters, $withCosts, $filterLabel);
        $this->writeSalespeople($writer, $organization, $filters, $withCosts);
        $rows = $this->writeEntries($writer, $organization, $filters, $withCosts);

        $writer->close();

        return ['path' => $path, 'filename' => "Commissions-{$filters->from}_{$filters->to}.xlsx", 'rows' => $rows];
    }

    private function writeSummary(Writer $writer, Organization $organization, CommissionReportFilters $filters, bool $withCosts, string $filterLabel): void
    {
        $writer->getCurrentSheet()->setName('Résumé');
        $kpis = $this->dashboard->kpis($organization, $filters);

        $writer->addRow(Row::fromValues(['Rapport des commissions'], (new Style)->setFontBold()->setFontSize(13)));
        $writer->addRow(Row::fromValues(['Organisation', $organization->name]));
        $writer->addRow(Row::fromValues(['Période (date de l’écriture)', $filters->from.' → '.$filters->to]));
        $writer->addRow(Row::fromValues(['Filtres', $filterLabel]));
        $writer->addRow(Row::fromValues(['Généré le', now()->format('d/m/Y H:i')]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Indicateur', 'Montant'], $this->header()));
        foreach ([
            'Commission brute générée' => $kpis['gross'],
            'Retours / annulations / corrections' => $kpis['reversals'],
            'Commission nette' => $kpis['net'],
            'En attente' => $kpis['pending'],
            'Approuvée' => $kpis['approved'],
            'Payée' => $kpis['paid'],
        ] as $label => $value) {
            $writer->addRow(Row::fromValues([$label, $this->number($value)]));
        }
        $writer->addRow(Row::fromValues(['Commandes commissionnées', $kpis['order_count']]));
        $writer->addRow(Row::fromValues(['Écritures', $kpis['entry_count']]));
        if ($withCosts) {
            $writer->addRow(Row::fromValues(['CA HT couvert', $this->number($kpis['revenue'])]));
            $writer->addRow(Row::fromValues(['Coût total', $this->number($kpis['cost'])]));
            $writer->addRow(Row::fromValues(['Marge brute', $this->number($kpis['margin'])]));
            $writer->addRow(Row::fromValues(['Marge %', $kpis['margin_rate'] === null ? 'n/d' : $this->number($kpis['margin_rate'])]));
            $writer->addRow(Row::fromValues(['Commission / marge %', $kpis['commission_to_margin_ratio'] === null ? 'n/d' : $this->number($kpis['commission_to_margin_ratio'])]));
        }
    }

    private function writeSalespeople(Writer $writer, Organization $organization, CommissionReportFilters $filters, bool $withCosts): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Commerciaux');
        $headers = ['Commercial', 'Commandes', 'CA HT'];
        if ($withCosts) {
            $headers[] = 'Marge brute';
        }
        array_push($headers, 'Commission brute', 'Retours / ajustements', 'Commission nette', 'En attente', 'Approuvée', 'Payée');
        $writer->addRow(Row::fromValues($headers, $this->header()));

        foreach ($this->dashboard->bySalesperson($organization, $filters) as $row) {
            $values = [$row['salesperson_name'], $row['orders'], $this->number($row['revenue'])];
            if ($withCosts) {
                $values[] = $this->number($row['margin']);
            }
            array_push($values, $this->number($row['gross']), $this->number($row['reversals']), $this->number($row['net']),
                $this->number($row['pending']), $this->number($row['approved']), $this->number($row['paid']));
            $writer->addRow(Row::fromValues($values));
        }
    }

    private function writeEntries(Writer $writer, Organization $organization, CommissionReportFilters $filters, bool $withCosts): int
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Écritures');
        $headers = ['Date', 'Date de vente', 'Commercial', 'Magasin', 'Commande', 'Produit', 'Type', 'Statut', 'CA HT'];
        if ($withCosts) {
            array_push($headers, 'Coût', 'Marge', 'Marge %');
        }
        array_push($headers, 'Commission %', 'Commission', 'Règle', 'Tranche', 'Payée le');
        $writer->addRow(Row::fromValues($headers, $this->header()));

        $count = 0;
        foreach ($this->dashboard->exportRows($organization, $filters) as $row) {
            $values = [
                substr((string) $row->occurred_at, 0, 10),
                substr((string) $row->sale_date, 0, 10),
                $row->salesperson_id === null ? 'Non attribuée' : $row->salesperson_name_snapshot,
                $row->store_name ?? '—',
                $row->order_number ?? '—',
                $row->product_name_snapshot ?? $row->line_reference_snapshot ?? '—',
                self::typeLabel($row->entry_type),
                self::statusLabel($row->status),
                $this->number($row->revenue_ht_snapshot),
            ];
            if ($withCosts) {
                array_push($values, $this->number($row->cost_total_snapshot), $this->number($row->margin_amount_snapshot), $this->number($row->margin_rate_snapshot));
            }
            array_push(
                $values,
                $this->number($row->commission_rate_snapshot),
                $this->number($row->commission_amount),
                $row->commission_rule_set_name_snapshot,
                self::tierLabel($row->rule_min_margin_snapshot, $row->rule_max_margin_snapshot),
                $row->paid_at ? substr((string) $row->paid_at, 0, 10) : '',
            );
            $writer->addRow(Row::fromValues($values));
            $count++;
        }

        return $count;
    }

    public static function typeLabel(string $type): string
    {
        return [
            'sale' => 'Vente', 'return_reversal' => 'Retour', 'correction' => 'Correction',
            'cancellation' => 'Annulation', 'manual_adjustment' => 'Ajustement',
        ][$type] ?? $type;
    }

    public static function statusLabel(string $status): string
    {
        return ['pending' => 'En attente', 'approved' => 'Approuvée', 'paid' => 'Payée'][$status] ?? $status;
    }

    public static function tierLabel(mixed $min, mixed $max): string
    {
        $format = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' %';
        if ($min === null && $max === null) {
            return 'Toutes marges';
        }
        if ($min === null) {
            return '< '.$format($max);
        }
        if ($max === null) {
            return '≥ '.$format($min);
        }

        return $format($min).' – '.$format($max);
    }

    private function header(): Style
    {
        return (new Style)->setFontBold()->setBackgroundColor('EEEEEE');
    }

    /** Numeric cell from a canonical decimal string (no float accumulation happened upstream). */
    private function number(mixed $value): float
    {
        return round((float) $this->dashboard->money($value), 4);
    }
}
