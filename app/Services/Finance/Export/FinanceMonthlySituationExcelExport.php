<?php

namespace App\Services\Finance\Export;

use App\Models\FinanceRecurringObligationTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Services\Finance\FinanceMonthReviewService;
use App\Services\Finance\FinanceMonthlySituationService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceTreasuryForecastService;
use App\Support\Decimal;
use App\Support\SpreadsheetSafeText;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;

class FinanceMonthlySituationExcelExport
{
    public function __construct(
        private readonly FinanceMonthlySituationService $situation,
        private readonly FinanceTreasuryForecastService $treasury,
        private readonly FinanceMonthReviewService $reviews,
    ) {}

    /** @param array<string,mixed> $detailFilters */
    public function build(Organization $organization, FinancePeriod $period, ?Store $store, array $detailFilters): string
    {
        $base = tempnam(sys_get_temp_dir(), 'finance_monthly_');
        if ($base === false) throw new RuntimeException('Impossible de préparer le fichier Excel.');
        @unlink($base);
        $path = $base.'.xlsx';
        $writer = new Writer;

        try {
            $writer->openToFile($path);
            $rows = $this->situation->exportRows($organization, $period, $store, $detailFilters);
            $this->situationSheet($writer, $organization, $period, $store, $rows);
            $this->supplierSheet($writer, $organization, $period, $store);
            $this->recurringSheet($writer, $organization, $period, $store);
            $this->futureSheet($writer, $organization, $period, $store);
            $this->expectedSheet($writer, $organization, $period, $store);
            $writer->close();
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new RuntimeException('Impossible de finaliser le fichier Excel.');
            return $bytes;
        } catch (Throwable $exception) {
            try { $writer->close(); } catch (Throwable) {}
            throw $exception;
        } finally {
            @unlink($path);
        }
    }

    private function situationSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store, iterable $rows): void
    {
        $sheet = $writer->getCurrentSheet(); $sheet->setName('Situation');
        foreach ([18,16,18,20,24,20,16,16,16,18,20] as $i => $width) $sheet->setColumnWidth($width, $i + 1);
        $sheet->setSheetView((new SheetView)->setFreezeRow(18));
        $kpis = $this->situation->kpis($organization, $period, $store);
        $forecast = $this->treasury->forecast($organization, $period, $store);
        $review = $this->reviews->state($organization, $period, $store);
        $expected = collect($this->treasury->expectedRows($organization, $period, $store, null))->where('status', '!=', 'cancelled')
            ->reduce(fn (string $sum, array $row) => Decimal::add($sum, $row['remaining']), '0.0000');

        $this->add($writer, ['Situation fin de mois'], $this->title());
        $this->add($writer, ['Organisation', $organization->name]);
        $this->add($writer, ['Période', $period->label()]);
        $this->add($writer, ['Boutique', $store?->name ?? 'Toutes les boutiques']);
        $this->add($writer, ['Exporté le', now()->format('d/m/Y H:i')]);
        $this->add($writer, ['Statut de revue', $this->reviewLabel($review['status']).($review['stale'] ? ' — données modifiées après clôture' : '')]);
        $this->add($writer, []);
        $this->add($writer, ['Indicateur', 'Montant'], $this->header());
        $this->add($writer, ['Solde disponible', $forecast['balance']['complete'] ? $this->money($forecast['balance']['total']) : 'Solde non configuré']);
        $this->add($writer, ['Total prévu', $this->money($kpis['planned'])]);
        $this->add($writer, ['Total payé', $this->money($kpis['paid'])]);
        $this->add($writer, ['Reste à payer', $this->money($kpis['remaining'])]);
        $this->add($writer, ['En retard', $this->money($kpis['overdue'])]);
        $this->add($writer, ['Encaissements attendus', $this->money($expected)]);
        $this->add($writer, ['Prévision fin de mois', $forecast['projected'] === null ? 'Indisponible' : $this->money($forecast['projected'])]);
        $this->add($writer, ['Manque', $forecast['shortage'] === null ? 'Indisponible' : $this->money($forecast['shortage'])], $this->total());
        $this->add($writer, []);
        $this->add($writer, ['Échéance','N°','Libellé','Type','Fournisseur / bénéficiaire','Référence','Prévu','Payé','Reste','Statut','Boutique'], $this->header());
        foreach ($rows as $row) {
            $this->add($writer, [$this->date($row['due_date']), $row['number'], $row['label'], $row['category'], $row['beneficiary'], $row['reference'] ?? '—',
                $this->money($row['planned']), $this->money($row['paid']), $this->money($row['remaining']), $row['status'], $row['store']], null,
                [0 => $this->dateStyle(), 6 => $this->moneyStyle(), 7 => $this->moneyStyle(), 8 => $this->moneyStyle()]);
        }
    }

    private function supplierSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent(); $sheet->setName('Fournisseurs'); $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        foreach ([28,18,18,20,18,18,18,16] as $i => $width) $sheet->setColumnWidth($width, $i + 1);
        $this->add($writer, ['Fournisseur','Total programmé','Payé','Engagé chèques/traites','Reste','En retard','Prochaine échéance','Nombre d’échéances'], $this->header());
        foreach ($this->situation->supplierSummary($organization, $period, $store) as $row) {
            $this->add($writer, [$row['beneficiary'], $this->money($row['planned']), $this->money($row['paid']), $this->money($row['committed']), $this->money($row['remaining']), $this->money($row['overdue']), $row['next_due_date'] ? $this->date($row['next_due_date']) : '—', $row['obligation_count']], null,
                [1=>$this->moneyStyle(),2=>$this->moneyStyle(),3=>$this->moneyStyle(),4=>$this->moneyStyle(),5=>$this->moneyStyle(),6=>$this->dateStyle()]);
        }
    }

    private function recurringSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent(); $sheet->setName('Charges récurrentes'); $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        foreach ([26,18,28,18,22,16,16,14,18] as $i => $width) $sheet->setColumnWidth($width, $i + 1);
        $this->add($writer, ['Libellé','Type','Fournisseur / bénéficiaire','Montant','Règle échéance','Début','Fin','Statut','Prochaine échéance'], $this->header());
        FinanceRecurringObligationTemplate::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->when($store, fn ($query) => $query->where('store_id', $store->id))->with('supplier:id,name')->orderBy('id')->lazyById(500)
            ->each(function ($row) use ($writer, $period) {
                $rule = $row->due_day_mode->label().($row->due_day ? ' · jour '.$row->due_day : '');
                $next = $row->active ? $period->start->copy()->day(min((int) ($row->due_day ?: $period->end->day), $period->end->day))->toDateString() : null;
                $this->add($writer, [$row->label, $row->category->label(), $row->supplier?->name ?: $row->beneficiary_name ?: '—', $this->money((string) $row->planned_amount),
                    $rule, $this->date($row->start_date->toDateString()), $row->end_date ? $this->date($row->end_date->toDateString()) : '—', $row->active ? 'Active' : 'Inactive', $next ? $this->date($next) : '—'], null,
                    [3=>$this->moneyStyle(),5=>$this->dateStyle(),6=>$this->dateStyle(),8=>$this->dateStyle()]);
            });
    }

    private function futureSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent(); $sheet->setName('Échéances futures'); $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        foreach ([16,28,18,22,18,24,16] as $i => $width) $sheet->setColumnWidth($width, $i + 1);
        $this->add($writer, ['Date','Fournisseur / bénéficiaire','Type','Référence','Montant','Source','Statut'], $this->header());
        foreach ($this->situation->futureSupplierObligations($organization, $period, $store, null) as $row) {
            $this->add($writer, [$this->date($row['due_date']), $row['supplier'], $row['category'], $row['reference'] ?? '—', $this->money($row['amount']), $row['source'], $row['status']], null,
                [0=>$this->dateStyle(),4=>$this->moneyStyle()]);
        }
    }

    private function expectedSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent(); $sheet->setName('Encaissements attendus'); $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        foreach ([16,26,18,20,18,18,18,18] as $i => $width) $sheet->setColumnWidth($width, $i + 1);
        $this->add($writer, ['Date prévue','Client','Sales Order','Référence','Montant prévu','Montant rapproché','Reste attendu','Statut'], $this->header());
        foreach ($this->treasury->expectedRows($organization, $period, $store, null) as $row) {
            $this->add($writer, [$this->date($row['expected_date']), $row['customer'], $row['order_number'], $row['reference'] ?? '—',
                $this->money($row['amount']), $this->money($row['matched']), $this->money($row['remaining']), $this->expectedLabel($row['status'])], null,
                [0=>$this->dateStyle(),4=>$this->moneyStyle(),5=>$this->moneyStyle(),6=>$this->moneyStyle()]);
        }
    }

    /** @param array<int,Style> $columnStyles */
    private function add(Writer $writer, array $values, ?Style $style = null, array $columnStyles = []): void
    {
        $writer->addRow(Row::fromValuesWithStyles(SpreadsheetSafeText::row($values), $style, $columnStyles));
    }

    private function date(string $date): DateTimeImmutable { return new DateTimeImmutable($date); }
    private function money(?string $value): float { return round((float) ($value ?? '0'), 2); }
    private function header(): Style { return (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('243B31'); }
    private function title(): Style { return (new Style)->setFontBold()->setFontSize(14)->setFontColor('243B31'); }
    private function total(): Style { return (new Style)->setFontBold()->setBackgroundColor('EAF0EC'); }
    private function moneyStyle(): Style { return (new Style)->setFormat('#,##0.00" MAD"'); }
    private function dateStyle(): Style { return (new Style)->setFormat('dd/mm/yyyy'); }
    private function reviewLabel(string $status): string { return ['open'=>'Ouvert','reviewed'=>'Vérifié','closed'=>'Clôturé'][$status] ?? $status; }
    private function expectedLabel(string $status): string { return ['planned'=>'Planifié','partial'=>'Partiellement reçu','received'=>'Reçu','overdue'=>'En retard','cancelled'=>'Annulé'][$status] ?? $status; }
}
