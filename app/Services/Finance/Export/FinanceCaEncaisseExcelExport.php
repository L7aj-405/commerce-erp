<?php

namespace App\Services\Finance\Export;

use App\Models\Organization;
use App\Models\Store;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * CA encaissé workbook: one sheet, closely matching the operational
 * spreadsheet columns (Numéro / Date de vente / Date de paiement / N°
 * facture-commande / Désignation / Client / Mode de paiement / Montant
 * encaissé / Statut), plus a visually separated monthly total row. Same
 * OpenSpout pipeline as FinanceSituationExcelExport — streamed to a temp
 * file, never buffering rows in a spreadsheet-library object graph, so an
 * arbitrarily large month never blows up memory.
 *
 * No logo: the installed openspout/openspout ~4.32 has no image-embedding
 * API at all (confirmed against its source — there is nothing resembling
 * addImage()/Image anywhere in the package). Rather than add a second
 * spreadsheet dependency just for a logo, the workbook leans on a strong
 * text header instead; the PDF export carries the actual logo branding.
 *
 * Dates are written as plain formatted strings ("12/09/2026"), not native
 * Excel date cells: OpenSpout only assigns a display number format when the
 * cell's Style explicitly sets one (there is no automatic date-shaped
 * default for a DateTimeCell), so a bare Carbon value would otherwise
 * render as a raw serial number. A formatted string keeps the exact same
 * "always readable" guarantee as FinanceSituationExcelExport's own date
 * columns, with zero risk of an unstyled numeric date.
 */
class FinanceCaEncaisseExcelExport
{
    private const HEADER_LABELS = [
        'Numéro', 'Date de vente', 'Date de paiement', 'N° facture / commande', 'Désignation',
        'Client', 'Mode de paiement', 'Montant encaissé', 'Statut',
    ];

    /** Excel column widths, in character units, matching HEADER_LABELS order. */
    private const COLUMN_WIDTHS = [14, 13, 13, 16, 42, 26, 16, 16, 20];

    /** 0-indexed position of the "Montant encaissé" column. */
    private const AMOUNT_COLUMN_INDEX = 7;

    public function __construct(private readonly FinanceCaEncaisseService $caEncaisse) {}

    /** @return string raw XLSX file bytes */
    public function build(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'finance_ca_encaisse_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($tempPath);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('CA encaissé');
        foreach (self::COLUMN_WIDTHS as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Row::fromValues(['CA encaissé — '.$period->label()], $this->titleStyle()));
        $writer->addRow(Row::fromValues(['Organisation', $organization->name]));
        $writer->addRow(Row::fromValues(['Boutique', $store?->name ?? 'Toutes les boutiques']));
        $writer->addRow(Row::fromValues(['Généré le', now()->format('d/m/Y H:i')]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(self::HEADER_LABELS, $this->headerStyle()));
        // Freeze the 5 metadata rows + blank + the header itself (row 6), so
        // the header stays visible while scrolling a long month.
        $sheet->setSheetView((new SheetView)->setFreezeRow(7));

        $amountStyle = $this->amountStyle();
        foreach ($this->caEncaisse->cursor($organization, $period, $store) as $row) {
            $values = [
                $row['payment_number'],
                Carbon::parse($row['sale_date'])->format('d/m/Y'),
                Carbon::parse($row['payment_date'])->format('d/m/Y'),
                $row['reference'],
                $row['designation'],
                $row['customer'],
                $row['method_label'],
                $this->amount($row['amount']),
                $row['status_label'],
            ];
            $writer->addRow(Row::fromValuesWithStyles($values, null, [self::AMOUNT_COLUMN_INDEX => $amountStyle]));
        }

        $total = $this->caEncaisse->total($organization, $period, $store);
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyles(
            ['CA encaissé du mois', '', '', '', '', '', '', $this->amount($total), ''],
            $this->totalLabelStyle(),
            [self::AMOUNT_COLUMN_INDEX => $this->totalAmountStyle()],
        ));

        $writer->close();

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);

        return $bytes === false ? '' : $bytes;
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('2B3A30')
            ->setCellAlignment(CellAlignment::LEFT);
    }

    private function titleStyle(): Style
    {
        return (new Style)->setFontBold()->setFontSize(13);
    }

    /** Right-aligned, DH-formatted numeric style shared by data and total rows. */
    private function amountStyle(): Style
    {
        return (new Style)
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setFormat('#,##0.00" DH"');
    }

    private function totalAmountStyle(): Style
    {
        return $this->amountStyle()
            ->setFontBold()
            ->setFontSize(11)
            ->setBorder(new Border(new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_MEDIUM, Border::STYLE_SOLID)));
    }

    private function totalLabelStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(11)
            ->setBorder(new Border(new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_MEDIUM, Border::STYLE_SOLID)));
    }

    private function amount(?string $value): float
    {
        // Presentation-only: the authoritative DECIMAL(19,4) string is never
        // used for calculation past this point — see
        // FinanceSituationExcelExport::amount() for the same convention.
        return round((float) ($value ?? '0'), 2);
    }
}
