<?php

namespace App\Services\Finance\Export;

use App\Models\Organization;
use App\Models\Store;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceReceivablesService;
use App\Support\Decimal;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Situation mensuelle workbook: Situation / Ventes / Encaissements /
 * Documents / Avoirs / Remboursements / Créances. Built with the existing OpenSpout dependency (already used
 * elsewhere in the project to READ product-import spreadsheets) — no Laravel
 * Excel or other package added.
 *
 * Every number here is produced server-side by the same Finance services the
 * UI uses; no formula depends on another file, and no value is a float —
 * every DECIMAL(19,4) string from the database is written into cells as
 * plain text-formatted numeric content via Decimal-safe formatting.
 */
class FinanceSituationExcelExport
{
    public function __construct(
        private readonly FinanceMonthlyReportService $report,
        private readonly FinanceReceivablesService $receivables,
    ) {}

    /** @return string raw XLSX file bytes */
    public function build(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'finance_situation_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($tempPath);

        $this->writeSituationSheet($writer, $organization, $period, $store);
        $this->writeVentesSheet($writer, $organization, $period, $store);
        $this->writeDocumentsSheet($writer, $organization, $period, $store);
        $this->writeEncaissementsSheet($writer, $organization, $period, $store);
        $this->writeAvoirsSheet($writer, $organization, $period, $store);
        $this->writeRemboursementsSheet($writer, $organization, $period, $store);
        $this->writeCreancesSheet($writer, $organization, $period, $store);

        $writer->close();

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);

        return $bytes === false ? '' : $bytes;
    }

    private function headerStyle(): Style
    {
        return (new Style)->setFontBold()->setBackgroundColor('EEEEEE');
    }

    private function titleStyle(): Style
    {
        return (new Style)->setFontBold()->setFontSize(13);
    }

    private function writeSituationSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Situation');

        $situation = $this->report->situation($organization, $period, $store);

        $writer->addRow(Row::fromValues(['Situation mensuelle'], $this->titleStyle()));
        $writer->addRow(Row::fromValues(['Organisation', $organization->name]));
        $writer->addRow(Row::fromValues(['Période', $period->label()]));
        $writer->addRow(Row::fromValues(['Boutique', $store?->name ?? 'Toutes les boutiques']));
        $writer->addRow(Row::fromValues(['Généré le', now()->format('d/m/Y H:i')]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['Indicateur', 'Montant TTC'], $this->headerStyle()));
        $writer->addRow(Row::fromValues(['Ventes brutes (sale_date)', $this->amount($situation['ventes'])]));
        $writer->addRow(Row::fromValues(['Ventes nettes (ventes brutes - avoirs de la période)', $this->amount($situation['ventes_nettes'])]));
        $writer->addRow(Row::fromValues(['Facturation brute (invoice_date)', $this->amount($situation['facturation_brute'])]));
        $writer->addRow(Row::fromValues(['Avoirs émis (credit_note_date)', $this->amount($situation['avoirs'])]));
        $writer->addRow(Row::fromValues(['Facturation nette', $this->amount($situation['facturation'])]));
        $writer->addRow(Row::fromValues(['Encaissements (payment_date)', $this->amount($situation['encaissements'])]));
        $writer->addRow(Row::fromValues(['Remboursements (refund_date)', $this->amount($situation['remboursements'])]));
        $writer->addRow(Row::fromValues(['Net encaissé', $this->amount($situation['net_encaisse'])]));
        $writer->addRow(Row::fromValues(['Obligations de remboursement', $this->amount($situation['obligations_remboursement'])]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Réconciliation créances', ''], $this->headerStyle()));
        $writer->addRow(Row::fromValues(['Créances début de période', $this->amount($situation['creances_debut'])]));
        $writer->addRow(Row::fromValues(['+ Facturation nette', $this->amount($situation['facturation'])]));
        $writer->addRow(Row::fromValues(['- Encaissements nets', $this->amount($situation['net_encaisse'])]));
        $writer->addRow(Row::fromValues(['= Créances fin de période (attendu)', $this->amount($situation['reconciliation']['expected_fin'])]));
        $writer->addRow(Row::fromValues(['Créances fin de période (calculée)', $this->amount($situation['creances_fin'])]));
        $writer->addRow(Row::fromValues(['Obligations de remboursement fin de période', $this->amount($situation['obligations_remboursement'])]));
        $writer->addRow(Row::fromValues(['Position nette client fin de période', $this->amount($situation['position_nette_fin'])]));
        if (Decimal::compare($situation['reconciliation']['variance'], '0.0000') !== 0) {
            $writer->addRow(Row::fromValues([
                'Écart (paiements sur commandes non encore facturées)',
                $this->amount($situation['reconciliation']['unapplied_payments']),
            ]));
        }
    }

    private function writeVentesSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Ventes');

        $writer->addRow(Row::fromValues(
            ['N° commande', 'Date de vente', 'Client', 'Total HT', 'TVA', 'Total TTC', 'Statut paiement'],
            $this->headerStyle(),
        ));

        foreach ($this->report->ventesCursor($organization, $period, $store) as $row) {
            $writer->addRow(Row::fromValues([
                $row['order_number'],
                $row['sale_date'],
                $row['customer'],
                $this->amount($row['subtotal_excl_tax']),
                $this->amount($row['tax_total']),
                $this->amount($row['total_incl_tax']),
                $this->paymentStatusLabel($row['payment_status']),
            ]));
        }
    }

    private function writeEncaissementsSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Encaissements');

        $writer->addRow(Row::fromValues(
            ['N° paiement', 'Date', 'Mode', 'Montant', 'Compte financier', 'N° commande', 'Client'],
            $this->headerStyle(),
        ));

        foreach ($this->report->encaissementsCursor($organization, $period, $store) as $row) {
            $writer->addRow(Row::fromValues([
                $row['payment_number'],
                $row['payment_date'],
                $row['method'],
                $this->amount($row['amount']),
                $row['financial_account'] ?? '—',
                $row['order_number'] ?? '—',
                $row['customer'] ?? '—',
            ]));
        }
    }

    private function writeDocumentsSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Documents');
        $writer->addRow(Row::fromValues([
            'Type document', 'N° document', 'Date', 'Client', 'Commande', 'Facture d’origine',
            'HT net', 'TVA', 'TTC', 'Effet', 'Montant net signé',
        ], $this->headerStyle()));

        foreach ($this->report->accountingDocumentsCursor($organization, $period, $store) as $row) {
            $writer->addRow(Row::fromValues([
                $row['document_type'], $row['document_number'], $row['document_date'], $row['customer'] ?: '—',
                $row['order_number'], $row['original_invoice_number'] ?: '—', $this->amount($row['net_ht']),
                $this->amount($row['tax_total']), $this->amount($row['total_incl_tax']), $row['effect'],
                $this->amount($row['net_amount']),
            ]));
        }
    }

    private function writeCreancesSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Créances');

        $writer->addRow(Row::fromValues(
            ['N° facture', 'Date facture', 'Client', 'Total HT', 'TVA', 'Total TTC', 'Payé', 'Solde', 'Statut'],
            $this->headerStyle(),
        ));

        foreach ($this->receivables->cursorAsOf($organization, $period->end, $store) as $row) {
            $writer->addRow(Row::fromValues([
                $row['invoice_number'] ?? '—',
                $row['invoice_date'],
                trim(($row['customer_company'] ?: $row['customer_name']) ?: '') ?: '—',
                $this->amount($row['subtotal_excl_tax'] ?? '0'),
                $this->amount($row['tax_total'] ?? '0'),
                $this->amount($row['total_incl_tax']),
                $this->amount($row['paid_amount']),
                $this->amount($row['outstanding']),
                $this->receivableStatusLabel($row['status']),
            ]));
        }
    }

    private function writeAvoirsSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Avoirs');
        $writer->addRow(Row::fromValues([
            'N° avoir', 'Date avoir', 'Facture d’origine', 'Version', 'Commande', 'Client', 'Qté', 'Référence', 'SKU',
            'Désignation', 'Variante', 'HT ligne', 'Remise ligne', 'Taux TVA', 'TVA ligne', 'TTC ligne',
            'Total HT document', 'Total TVA document', 'Total TTC document',
        ], $this->headerStyle()));

        $lastCreditNoteId = null;
        foreach ($this->report->creditNotesCursor($organization, $period, $store) as $row) {
            $firstDocumentRow = $lastCreditNoteId !== $row['credit_note_id'];
            $writer->addRow(Row::fromValues([
                $row['credit_note_number'], $row['credit_note_date'], $row['invoice_number'], $row['invoice_version'],
                $row['order_number'], $row['customer'] ?: '—', $this->amount($row['quantity']), $row['reference'], $row['sku'],
                $row['description'], $row['variant'], $this->amount($row['line_ht']), $this->amount($row['line_discount']),
                $this->amount($row['tax_rate']), $this->amount($row['line_tax']), $this->amount($row['line_ttc']),
                $firstDocumentRow ? $this->amount($row['document_ht']) : null,
                $firstDocumentRow ? $this->amount($row['document_tax']) : null,
                $firstDocumentRow ? $this->amount($row['document_ttc']) : null,
            ]));
            $lastCreditNoteId = $row['credit_note_id'];
        }
    }

    private function writeRemboursementsSheet(Writer $writer, Organization $organization, FinancePeriod $period, ?Store $store): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Remboursements');
        $writer->addRow(Row::fromValues([
            'N° remboursement', 'Date', 'Montant', 'Mode', 'Compte financier', 'Paiement source', 'Commande', 'Retour', 'Client', 'Motif',
        ], $this->headerStyle()));

        foreach ($this->report->refundsCursor($organization, $period, $store) as $row) {
            $writer->addRow(Row::fromValues([
                $row['refund_number'], $row['refund_date'], $this->amount($row['amount']), $row['method'],
                $row['financial_account'], $row['payment_number'], $row['order_number'], $row['return_number'] ?: '—',
                $row['customer'] ?: '—', $row['reason'],
            ]));
        }
    }

    private function amount(?string $value): float
    {
        // XLSX cells are genuinely numeric only at the presentation layer —
        // the authoritative DECIMAL(19,4) string from the database is never
        // used for calculation past this point; OpenSpout cells require a
        // native float/int for a numeric cell, exactly like every other
        // "format a Decimal string for display" call in this codebase.
        return round((float) ($value ?? '0'), 2);
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Payée',
            'partially_paid' => 'Partiellement payée',
            default => 'Impayée',
        };
    }

    private function receivableStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Soldée',
            'partial' => 'Partiellement payée',
            default => 'Impayée',
        };
    }
}
